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
        // Ищем пользователя с данной ролью в организации через новую систему авторизации
        $context = \App\Domain\Authorization\Models\AuthorizationContext::getOrganizationContext($organizationId);
        
        return User::whereHas('roleAssignments', function ($query) use ($context, $role) {
            $query->where('context_id', $context->id)
                ->where('role_slug', $role);
        })->first();
    }

    /**
     * Утвердить документ пользователем
     */
    public function approveByUser(PaymentDocument $document, int $userId, ?string $comment = null): bool
    {
        DB::beginTransaction();

        try {
            // Блокируем документ для предотвращения гонок
            $document = PaymentDocument::where('id', $document->id)->lockForUpdate()->first();

            // Найти pending утверждение для данного пользователя
            $approval = PaymentApproval::where('payment_document_id', $document->id)
                ->where('approver_user_id', $userId)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();

            $user = User::find($userId);
            
            // Проверка прав администратора/владельца организации
            $isAdmin = false;
            if ($user) {
                $organizationId = $document->organization_id;
                
                // 1. Проверка владельца организации (GOD MODE)
                if ($user->isOrganizationOwner($organizationId)) {
                    $isAdmin = true;
                    Log::info('payment_approval.auth_check', [
                        'user_id' => $userId,
                        'document_id' => $document->id,
                        'organization_id' => $organizationId,
                        'check' => 'isOrganizationOwner',
                        'result' => true,
                    ]);
                }
                
                // 2. Проверка системного администратора
                if (!$isAdmin && $user->isSystemAdmin()) {
                    $isAdmin = true;
                    Log::info('payment_approval.auth_check', [
                        'user_id' => $userId,
                        'document_id' => $document->id,
                        'check' => 'isSystemAdmin',
                        'result' => true,
                    ]);
                }
                
                // 3. Проверка ролей админа/финдира в контексте организации
                if (!$isAdmin) {
                    $context = \App\Domain\Authorization\Models\AuthorizationContext::getOrganizationContext($organizationId);
                    $rolesToCheck = ['admin', 'finance_admin'];
                    foreach ($rolesToCheck as $role) {
                        if ($user->hasRole($role, $context->id)) {
                            $isAdmin = true;
                            Log::info('payment_approval.auth_check', [
                                'user_id' => $userId,
                                'document_id' => $document->id,
                                'organization_id' => $organizationId,
                                'check' => "hasRole({$role})",
                                'result' => true,
                            ]);
                            break;
                        }
                    }
                }
                
                // 4. Проверка конкретного разрешения с контекстом организации
                if (!$isAdmin) {
                    $isAdmin = $user->can('payments.transaction.approve', ['organization_id' => $organizationId]);
                    Log::info('payment_approval.auth_check', [
                        'user_id' => $userId,
                        'document_id' => $document->id,
                        'organization_id' => $organizationId,
                        'check' => 'can(payments.transaction.approve)',
                        'result' => $isAdmin,
                    ]);
                }
            }

            // Если нет прямого назначения, но есть права админа/владельца - ищем любое активное утверждение
            if (!$approval && $isAdmin) {
                // Берем первое попавшееся активное утверждение, чтобы закрыть текущий уровень
                $approval = PaymentApproval::where('payment_document_id', $document->id)
                    ->where('status', 'pending')
                    ->orderBy('approval_level')
                    ->lockForUpdate()
                    ->first();

                if ($approval) {
                    $comment = trim(($comment ?? '') . " [Утверждено администратором/владельцем]");
                }
            }

            // Детальное логирование для отладки
            Log::info('payment_approval.approve_check', [
                'user_id' => $userId,
                'document_id' => $document->id,
                'document_status' => $document->status->value,
                'organization_id' => $document->organization_id,
                'has_approval' => $approval !== null,
                'is_admin' => $isAdmin,
                'approval_count' => PaymentApproval::where('payment_document_id', $document->id)->count(),
                'pending_approval_count' => PaymentApproval::where('payment_document_id', $document->id)
                    ->where('status', 'pending')
                    ->count(),
            ]);

            if (!$approval) {
                // Если админ, но нет записей утверждения - проверяем статус документа
                if ($isAdmin) {
                    // Для админа разрешаем утверждение в статусах submitted или pending_approval
                    if (in_array($document->status, [PaymentDocumentStatus::SUBMITTED, PaymentDocumentStatus::PENDING_APPROVAL])) {
                        // Форс-мажор: документ висит, а апрувов нет. Утверждаем напрямую.
                        Log::info('payment_approval.admin_override', [
                            'user_id' => $userId,
                            'document_id' => $document->id,
                            'status' => $document->status->value,
                        ]);
                        
                        // Создаем запись утверждения для истории (даже если правил нет)
                        PaymentApproval::create([
                            'payment_document_id' => $document->id,
                            'organization_id' => $document->organization_id,
                            'approval_role' => 'admin',
                            'approver_user_id' => $userId,
                            'approval_level' => 1,
                            'approval_order' => 1,
                            'status' => 'approved',
                            'decision_comment' => 'Утверждено администратором/владельцем организации',
                            'decided_at' => now(),
                        ]);
                        
                        $this->stateMachine->approve($document, $userId);
                        DB::commit();
                        return true;
                    } else {
                        Log::warning('payment_approval.admin_cannot_approve_wrong_status', [
                            'user_id' => $userId,
                            'document_id' => $document->id,
                            'status' => $document->status->value,
                        ]);
                        throw new \DomainException("Документ находится в статусе '{$document->status->label()}' и не требует утверждения");
                    }
                }
                
                Log::warning('payment_approval.no_rights', [
                    'user_id' => $userId,
                    'document_id' => $document->id,
                    'is_admin' => $isAdmin,
                    'has_approval' => false,
                ]);
                throw new \DomainException('У вас нет прав на утверждение этого документа или он не требует утверждения');
            }

            // Проверка лимита суммы (пропускаем для админов)
            if (!$isAdmin && !$approval->canApproveAmount($document->amount)) {
                throw new \DomainException('Сумма документа превышает ваш лимит утверждения');
            }

            // Проверить условия (пропускаем для админов)
            if (!$isAdmin && !$approval->checkConditions($document)) {
                throw new \DomainException('Документ не соответствует условиям утверждения');
            }

            // Утвердить конкретный шаг
            $approval->approve($comment);
            
            // Если это админ, утвердим все остальные шаги этого уровня тоже, чтобы не застряло
            if ($isAdmin) {
                PaymentApproval::where('payment_document_id', $document->id)
                    ->where('approval_level', $approval->approval_level)
                    ->where('status', 'pending')
                    ->get()
                    ->each(fn($a) => $a->approve("Автоматически утверждено (Override by Admin)"));
            }

            Log::info('payment_approval.approved', [
                'document_id' => $document->id,
                'approval_id' => $approval->id,
                'user_id' => $userId,
                'role' => $approval->approval_role,
                'is_admin_override' => $isAdmin,
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
            // Блокируем документ
            $document = PaymentDocument::where('id', $document->id)->lockForUpdate()->first();

            // Найти pending утверждение для данного пользователя
            $approval = PaymentApproval::where('payment_document_id', $document->id)
                ->where('approver_user_id', $userId)
                ->where('status', 'pending')
                ->lockForUpdate()
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
    public function getPendingApprovalsForUser(int $userId, ?int $organizationId = null): Collection
    {
        $isAdmin = false;
        
        if ($organizationId) {
            $user = User::find($userId);
            if ($user) {
                // 1. Проверка владельца организации
                if ($user->isOrganizationOwner($organizationId)) {
                    $isAdmin = true;
                }
                
                // 2. Проверка системного администратора
                if (!$isAdmin && $user->isSystemAdmin()) {
                    $isAdmin = true;
                }
                
                // 3. Проверка ролей админа/финдира в контексте организации
                if (!$isAdmin) {
                    $context = \App\Domain\Authorization\Models\AuthorizationContext::getOrganizationContext($organizationId);
                    $rolesToCheck = ['admin', 'finance_admin'];
                    foreach ($rolesToCheck as $role) {
                        if ($user->hasRole($role, $context->id)) {
                            $isAdmin = true;
                            break;
                        }
                    }
                }
                
                // 4. Проверка конкретного разрешения с контекстом организации
                if (!$isAdmin) {
                    $isAdmin = $user->can('payments.transaction.approve', ['organization_id' => $organizationId]);
                }
            }
        }

        $query = PaymentApproval::with(['paymentDocument', 'organization'])
            ->where('status', 'pending');
            
        if ($isAdmin && $organizationId) {
            // Для админа/владельца показываем все pending утверждения организации
            $query->where('organization_id', $organizationId);
            
            Log::info('payment_approval.get_pending_for_admin', [
                'user_id' => $userId,
                'organization_id' => $organizationId,
                'is_admin' => $isAdmin,
            ]);
        } else {
            // Для обычных пользователей - только назначенные им
            $query->where('approver_user_id', $userId);
            
            Log::info('payment_approval.get_pending_for_user', [
                'user_id' => $userId,
                'organization_id' => $organizationId,
                'is_admin' => $isAdmin,
            ]);
        }
        
        // Также для админа показываем документы в статусе pending_approval без записей утверждения
        // (если правила утверждения не создали записи)
        if ($isAdmin && $organizationId) {
            $documentsWithoutApprovals = PaymentDocument::where('organization_id', $organizationId)
                ->where('status', PaymentDocumentStatus::PENDING_APPROVAL)
                ->whereDoesntHave('approvals', function ($q) {
                    $q->where('status', 'pending');
                })
                ->get();
            
            Log::info('payment_approval.documents_without_approvals', [
                'organization_id' => $organizationId,
                'count' => $documentsWithoutApprovals->count(),
            ]);
            
            // Для таких документов создаем виртуальные записи утверждения для админа
            foreach ($documentsWithoutApprovals as $doc) {
                // Проверяем, нет ли уже записи для этого документа и пользователя
                $existingApproval = PaymentApproval::where('payment_document_id', $doc->id)
                    ->where('approver_user_id', $userId)
                    ->first();
                
                if (!$existingApproval) {
                    // Создаем запись утверждения для админа
                    PaymentApproval::create([
                        'payment_document_id' => $doc->id,
                        'organization_id' => $doc->organization_id,
                        'approval_role' => 'admin',
                        'approver_user_id' => $userId,
                        'approval_level' => 1,
                        'approval_order' => 1,
                        'status' => 'pending',
                        'decision_comment' => null,
                    ]);
                }
            }
            
            // Перезагружаем запрос после создания записей
            $query = PaymentApproval::with(['paymentDocument', 'organization'])
                ->where('status', 'pending')
                ->where('organization_id', $organizationId);
        }
            
        return $query->orderBy('created_at', 'desc')
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

        // Если документ утвержден, но нет записей утверждения - создаем ретроспективную запись
        if ($document->status === PaymentDocumentStatus::APPROVED && $total === 0) {
            // Пытаемся найти пользователя, который утвердил документ
            $approvedByUserId = $document->approved_by_user_id;
            
            // Если нет информации о пользователе, пытаемся найти через логи или используем системного пользователя
            if (!$approvedByUserId) {
                // Ищем в логах последнего, кто утвердил документ
                // Или используем первого админа организации как fallback
                $orgAdmin = User::whereHas('roleAssignments', function ($query) use ($document) {
                    $query->where('role_slug', 'organization_owner')
                        ->whereHas('context', function ($q) use ($document) {
                            $q->where('type', \App\Domain\Authorization\Models\AuthorizationContext::TYPE_ORGANIZATION)
                              ->where('resource_id', $document->organization_id);
                        });
                })->first();
                
                $approvedByUserId = $orgAdmin?->id ?? 1; // Fallback на системного пользователя
            }
            
            // Создаем запись для истории (ретроспективно)
            PaymentApproval::create([
                'payment_document_id' => $document->id,
                'organization_id' => $document->organization_id,
                'approval_role' => 'admin',
                'approver_user_id' => $approvedByUserId,
                'approval_level' => 1,
                'approval_order' => 1,
                'status' => 'approved',
                'decision_comment' => 'Утверждено (ретроспективная запись)',
                'decided_at' => $document->approved_at ?? now(),
            ]);
            
            // Перезагружаем approvals
            $approvals = $this->getApprovalHistory($document);
            $total = $approvals->count();
            $approved = $approvals->where('status', 'approved')->count();
        }

        $currentLevel = null;
        if ($pending > 0) {
            $firstPending = $approvals->where('status', 'pending')->first();
            $currentLevel = $firstPending?->approval_level;
        }

        // Если документ утвержден, считаем его полностью утвержденным (даже без записей)
        $isFullyApproved = false;
        if ($document->status === PaymentDocumentStatus::APPROVED) {
            $isFullyApproved = true; // Документ в статусе approved = полностью утвержден
        } else {
            // Для других статусов проверяем наличие записей
            $isFullyApproved = $pending === 0 && $rejected === 0 && $total > 0;
        }

        return [
            'total' => $total,
            'approved' => $approved,
            'rejected' => $rejected,
            'pending' => $pending,
            'progress_percentage' => $total > 0 ? round(($approved / $total) * 100, 2) : ($isFullyApproved ? 100 : 0),
            'current_level' => $currentLevel,
            'is_fully_approved' => $isFullyApproved,
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

