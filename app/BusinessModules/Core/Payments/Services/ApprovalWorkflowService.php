<?php

namespace App\BusinessModules\Core\Payments\Services;

use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Models\PaymentApproval;
use App\BusinessModules\Core\Payments\Models\PaymentApprovalRule;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ApprovalWorkflowService
{
    public function __construct(
        private readonly PaymentDocumentStateMachine $stateMachine
    ) {}

    /**
     * Инициировать процесс утверждения для документа
     */
    public function initiateApproval(PaymentDocument $document): Collection
    {
        // Найти подходящее правило утверждения
        $rule = $this->findApplicableRule($document);

        if (!$rule) {
            // Если правила нет, используем дефолтное утверждение
            $rule = $this->getDefaultApprovalRule($document);
        }

        if (!$rule) {
            // Если утверждение вообще не требуется
            Log::info('payment_approval.no_rules', [
                'document_id' => $document->id,
                'document_number' => $document->document_number,
            ]);

            // Автоматически утверждаем
            $this->stateMachine->approve($document);
            
            return collect();
        }

        // Создаем записи утверждений
        $approvals = $this->createApprovals($document, $rule);

        // Меняем статус документа на "ожидает утверждения"
        $this->stateMachine->sendForApproval($document);

        // Уведомляем первый уровень утверждающих
        $this->notifyPendingApprovers($document, 1);

        Log::info('payment_approval.initiated', [
            'document_id' => $document->id,
            'document_number' => $document->document_number,
            'rule_id' => $rule->id,
            'rule_name' => $rule->name,
            'approvals_count' => $approvals->count(),
        ]);

        return $approvals;
    }

    /**
     * Найти применимое правило утверждения
     */
    private function findApplicableRule(PaymentDocument $document): ?PaymentApprovalRule
    {
        $rules = PaymentApprovalRule::forOrganization($document->organization_id)
            ->active()
            ->byPriority()
            ->get();

        foreach ($rules as $rule) {
            if ($rule->matches($document)) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * Получить дефолтное правило утверждения
     */
    private function getDefaultApprovalRule(PaymentDocument $document): ?PaymentApprovalRule
    {
        // Создаем виртуальное правило на основе суммы
        $amount = $document->amount;
        
        if ($amount < 50000) {
            // До 50к - только главный бухгалтер
            return $this->createVirtualRule($document, [
                ['role' => 'chief_accountant', 'level' => 1, 'order' => 1, 'required' => true]
            ]);
        } elseif ($amount < 500000) {
            // До 500к - главбух + финдир
            return $this->createVirtualRule($document, [
                ['role' => 'chief_accountant', 'level' => 1, 'order' => 1, 'required' => true],
                ['role' => 'financial_director', 'level' => 2, 'order' => 1, 'required' => true],
            ]);
        } else {
            // Более 500к - главбух + финдир + гендир
            return $this->createVirtualRule($document, [
                ['role' => 'chief_accountant', 'level' => 1, 'order' => 1, 'required' => true],
                ['role' => 'financial_director', 'level' => 2, 'order' => 1, 'required' => true],
                ['role' => 'general_director', 'level' => 3, 'order' => 1, 'required' => true],
            ]);
        }
    }

    /**
     * Создать виртуальное правило (не сохраняется в БД)
     */
    private function createVirtualRule(PaymentDocument $document, array $chain): PaymentApprovalRule
    {
        $rule = new PaymentApprovalRule();
        $rule->organization_id = $document->organization_id;
        $rule->name = 'Автоматическое правило';
        $rule->approval_chain = $chain;
        $rule->is_active = true;
        
        return $rule;
    }

    /**
     * Создать записи утверждений на основе правила
     */
    private function createApprovals(PaymentDocument $document, PaymentApprovalRule $rule): Collection
    {
        $chain = $rule->getApprovalChain();
        $approvals = collect();

        foreach ($chain as $item) {
            // Найти пользователя с данной ролью
            $approver = $this->findApproverByRole($document->organization_id, $item['role']);

            $approval = PaymentApproval::create([
                'payment_document_id' => $document->id,
                'organization_id' => $document->organization_id,
                'approval_role' => $item['role'],
                'approver_user_id' => $approver?->id,
                'approval_level' => $item['level'],
                'approval_order' => $item['order'] ?? 1,
                'amount_threshold' => $item['amount_threshold'] ?? null,
                'conditions' => $item['conditions'] ?? null,
                'status' => 'pending',
            ]);

            $approvals->push($approval);
        }

        return $approvals;
    }

    /**
     * Найти утверждающего по роли
     */
    private function findApproverByRole(int $organizationId, string $role): ?User
    {
        // Ищем пользователя с данной ролью в организации
        return User::whereHas('organizationUsers', function ($query) use ($organizationId, $role) {
            $query->where('organization_id', $organizationId)
                ->where('role', $role);
        })->first();
    }

    /**
     * Утвердить документ пользователем
     */
    public function approveByUser(PaymentDocument $document, int $userId, ?string $comment = null): bool
    {
        DB::beginTransaction();

        try {
            // Найти pending утверждение для данного пользователя
            $approval = PaymentApproval::where('payment_document_id', $document->id)
                ->where('approver_user_id', $userId)
                ->where('status', 'pending')
                ->first();

            if (!$approval) {
                throw new \DomainException('У вас нет прав на утверждение этого документа');
            }

            // Проверить лимит суммы
            if (!$approval->canApproveAmount($document->amount)) {
                throw new \DomainException('Сумма документа превышает ваш лимит утверждения');
            }

            // Проверить условия
            if (!$approval->checkConditions($document)) {
                throw new \DomainException('Документ не соответствует условиям утверждения');
            }

            // Утвердить
            $approval->approve($comment);

            Log::info('payment_approval.approved', [
                'document_id' => $document->id,
                'approval_id' => $approval->id,
                'user_id' => $userId,
                'role' => $approval->approval_role,
            ]);

            // Проверить, все ли утверждения текущего уровня завершены
            $currentLevel = $approval->approval_level;
            $this->checkLevelCompletion($document, $currentLevel);

            // Проверить, все ли утверждения завершены
            if ($this->isFullyApproved($document)) {
                $this->stateMachine->approve($document, $userId);
                
                Log::info('payment_approval.fully_approved', [
                    'document_id' => $document->id,
                    'document_number' => $document->document_number,
                ]);
            }

            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('payment_approval.approve_failed', [
                'document_id' => $document->id,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Отклонить документ пользователем
     */
    public function rejectByUser(PaymentDocument $document, int $userId, string $reason): bool
    {
        DB::beginTransaction();

        try {
            // Найти pending утверждение для данного пользователя
            $approval = PaymentApproval::where('payment_document_id', $document->id)
                ->where('approver_user_id', $userId)
                ->where('status', 'pending')
                ->first();

            if (!$approval) {
                throw new \DomainException('У вас нет прав на отклонение этого документа');
            }

            // Отклонить
            $approval->reject($reason);

            // Отменить все остальные pending утверждения
            PaymentApproval::where('payment_document_id', $document->id)
                ->where('status', 'pending')
                ->update(['status' => 'skipped']);

            // Изменить статус документа
            $this->stateMachine->reject($document, $reason);

            Log::info('payment_approval.rejected', [
                'document_id' => $document->id,
                'approval_id' => $approval->id,
                'user_id' => $userId,
                'reason' => $reason,
            ]);

            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('payment_approval.reject_failed', [
                'document_id' => $document->id,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Проверить завершение уровня утверждения
     */
    private function checkLevelCompletion(PaymentDocument $document, int $level): void
    {
        $levelApprovals = PaymentApproval::where('payment_document_id', $document->id)
            ->where('approval_level', $level)
            ->get();

        $allApproved = $levelApprovals->every(fn($a) => $a->status === 'approved');

        if ($allApproved) {
            // Все утверждения текущего уровня завершены, уведомляем следующий уровень
            $nextLevel = $level + 1;
            $this->notifyPendingApprovers($document, $nextLevel);
        }
    }

    /**
     * Проверить, полностью ли утвержден документ
     */
    private function isFullyApproved(PaymentDocument $document): bool
    {
        $pendingCount = PaymentApproval::where('payment_document_id', $document->id)
            ->where('status', 'pending')
            ->count();

        return $pendingCount === 0;
    }

    /**
     * Уведомить утверждающих о необходимости утверждения
     */
    private function notifyPendingApprovers(PaymentDocument $document, int $level): void
    {
        $approvals = PaymentApproval::where('payment_document_id', $document->id)
            ->where('approval_level', $level)
            ->where('status', 'pending')
            ->get();

        foreach ($approvals as $approval) {
            if ($approval->approver_user_id) {
                // Отправляем уведомление (будет реализовано позже в notifications)
                $approval->markAsNotified();
                
                Log::info('payment_approval.notification_sent', [
                    'approval_id' => $approval->id,
                    'user_id' => $approval->approver_user_id,
                    'role' => $approval->approval_role,
                ]);
            }
        }
    }

    /**
     * Получить pending утверждения для пользователя
     */
    public function getPendingApprovalsForUser(int $userId): Collection
    {
        return PaymentApproval::with(['paymentDocument', 'organization'])
            ->where('approver_user_id', $userId)
            ->where('status', 'pending')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Получить историю утверждений документа
     */
    public function getApprovalHistory(PaymentDocument $document): Collection
    {
        return PaymentApproval::with(['approver'])
            ->where('payment_document_id', $document->id)
            ->orderBy('approval_level')
            ->orderBy('approval_order')
            ->get();
    }

    /**
     * Получить текущий статус утверждения
     */
    public function getApprovalStatus(PaymentDocument $document): array
    {
        $approvals = $this->getApprovalHistory($document);
        
        $total = $approvals->count();
        $approved = $approvals->where('status', 'approved')->count();
        $rejected = $approvals->where('status', 'rejected')->count();
        $pending = $approvals->where('status', 'pending')->count();

        $currentLevel = null;
        if ($pending > 0) {
            $firstPending = $approvals->where('status', 'pending')->first();
            $currentLevel = $firstPending?->approval_level;
        }

        return [
            'total' => $total,
            'approved' => $approved,
            'rejected' => $rejected,
            'pending' => $pending,
            'progress_percentage' => $total > 0 ? round(($approved / $total) * 100, 2) : 0,
            'current_level' => $currentLevel,
            'is_fully_approved' => $pending === 0 && $rejected === 0 && $total > 0,
            'is_rejected' => $rejected > 0,
            'approvals' => $approvals->map(fn($a) => [
                'id' => $a->id,
                'role' => $a->approval_role,
                'role_label' => $a->getRoleLabel(),
                'approver' => $a->approver?->name,
                'level' => $a->approval_level,
                'order' => $a->approval_order,
                'status' => $a->status,
                'status_label' => $a->getStatusLabel(),
                'comment' => $a->decision_comment,
                'decided_at' => $a->decided_at?->toDateTimeString(),
            ]),
        ];
    }

    /**
     * Отправить напоминания утверждающим
     */
    public function sendReminders(PaymentDocument $document): int
    {
        $approvals = PaymentApproval::where('payment_document_id', $document->id)
            ->where('status', 'pending')
            ->get();

        $sentCount = 0;

        foreach ($approvals as $approval) {
            if ($approval->canSendReminder()) {
                // Отправляем напоминание (будет реализовано в notifications)
                $approval->markReminderSent();
                $sentCount++;

                Log::info('payment_approval.reminder_sent', [
                    'approval_id' => $approval->id,
                    'user_id' => $approval->approver_user_id,
                    'reminder_count' => $approval->reminder_count,
                ]);
            }
        }

        return $sentCount;
    }

    /**
     * Пропустить утверждение (для необязательных утверждений или автоматизации)
     */
    public function skipApproval(PaymentApproval $approval, string $reason): bool
    {
        $approval->skip($reason);

        Log::info('payment_approval.skipped', [
            'approval_id' => $approval->id,
            'document_id' => $approval->payment_document_id,
            'reason' => $reason,
        ]);

        // Проверить, может быть уже все утверждено
        $document = $approval->paymentDocument;
        if ($this->isFullyApproved($document)) {
            $this->stateMachine->approve($document);
        }

        return true;
    }

    /**
     * Переназначить утверждение другому пользователю
     */
    public function reassignApproval(PaymentApproval $approval, int $newUserId, string $reason): bool
    {
        $oldUserId = $approval->approver_user_id;
        
        $approval->approver_user_id = $newUserId;
        $approval->notes = ($approval->notes ? $approval->notes . "\n\n" : '') 
            . "Переназначено: {$reason}";
        $approval->save();

        // Отправляем уведомление новому утверждающему
        $approval->markAsNotified();

        Log::info('payment_approval.reassigned', [
            'approval_id' => $approval->id,
            'old_user_id' => $oldUserId,
            'new_user_id' => $newUserId,
            'reason' => $reason,
        ]);

        return true;
    }
}

