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

use function trans_message;

class ApprovalWorkflowService
{
    private const PAYMENT_APPROVAL_PERMISSION = 'payments.transaction.approve';

    public function __construct(
        private readonly PaymentDocumentStateMachine $stateMachine,
        private readonly PaymentBudgetLimitService $budgetLimitService
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
            $this->budgetLimitService->assertAllowed(
                $document,
                PaymentBudgetLimitService::OPERATION_APPROVAL,
                (float) $document->amount
            );
            $this->stateMachine->approve($document);
            $this->budgetLimitService->syncReservation($document->fresh());
            
            return collect();
        }

        // Создаем записи утверждений
        $approvals = $this->createApprovals($document, $rule);

        // Меняем статус документа на "ожидает утверждения"
        $this->stateMachine->sendForApproval($document);
        $this->budgetLimitService->syncReservation($document->fresh());

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
        $amount = $document->amount;

        $levels = match (true) {
            $amount < 50000 => 1,
            $amount < 500000 => 2,
            default => 3,
        };

        $chain = [];
        for ($level = 1; $level <= $levels; $level++) {
            $chain[] = [
                'permission' => self::PAYMENT_APPROVAL_PERMISSION,
                'level' => $level,
                'order' => 1,
                'required' => true,
            ];
        }

        return $this->createVirtualRule($document, $chain);
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
            $permission = $item['approval_permission'] ?? $item['permission'] ?? null;
            $role = $permission ? null : ($item['role'] ?? null);
            $approverUserId = $item['approver_user_id'] ?? null;

            if (!$permission && $role) {
                $approverUserId = $this->findApproverByRole($document->organization_id, $role)?->id;
            }

            $approval = PaymentApproval::create([
                'payment_document_id' => $document->id,
                'organization_id' => $document->organization_id,
                'approval_role' => $role,
                'approval_permission' => $permission,
                'approver_user_id' => $approverUserId,
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

    private function approvalQueryForUser(PaymentDocument $document, int $userId, ?User $user)
    {
        $query = PaymentApproval::where('payment_document_id', $document->id)
            ->where('status', 'pending');

        return $query->where(function ($scope) use ($document, $userId, $user): void {
            $scope->where('approver_user_id', $userId);

            if ($this->userHasPermission($user, self::PAYMENT_APPROVAL_PERMISSION, (int) $document->organization_id)) {
                $scope->orWhere(function ($permissionScope): void {
                    $permissionScope->whereNull('approver_user_id')
                        ->where('approval_permission', self::PAYMENT_APPROVAL_PERMISSION);
                });
            }
        });
    }

    private function userHasPermission(?User $user, string $permission, int $organizationId): bool
    {
        if (!$user) {
            return false;
        }

        return $user->can($permission, ['organization_id' => $organizationId]);
    }

    private function isPrivilegedApprovalActor(?User $user, int $organizationId): bool
    {
        if (!$user) {
            return false;
        }

        return $user->isOrganizationOwner($organizationId) || $user->isSystemAdmin();
    }

    private function legacyRoleApprovalForUser(PaymentDocument $document, int $userId): ?PaymentApproval
    {
        $context = \App\Domain\Authorization\Models\AuthorizationContext::getOrganizationContext((int) $document->organization_id);
        $user = User::find($userId);

        if (!$user) {
            return null;
        }

        $legacyApprovals = PaymentApproval::where('payment_document_id', $document->id)
            ->where('status', 'pending')
            ->whereNull('approval_permission')
            ->whereNotNull('approval_role')
            ->lockForUpdate()
            ->get();

        return $legacyApprovals->first(function (PaymentApproval $approval) use ($user, $userId, $context): bool {
            if ($approval->approver_user_id === $userId) {
                return true;
            }

            return $approval->approval_role && $user->hasRole($approval->approval_role, $context->id);
        });
    }

    /**
     * Утвердить документ пользователем
     */
    public function approveByUser(
        PaymentDocument $document,
        int $userId,
        ?string $comment = null,
        ?string $budgetOverrideReason = null
    ): bool {
        DB::beginTransaction();

        try {
            // Блокируем документ для предотвращения гонок
            $document = PaymentDocument::where('id', $document->id)->lockForUpdate()->first();

            // Найти pending утверждение для данного пользователя
            $user = User::find($userId);
            $approval = $this->approvalQueryForUser($document, $userId, $user)
                ->lockForUpdate()
                ->first();
            $approval ??= $this->legacyRoleApprovalForUser($document, $userId);
            $canApprovePayments = $this->userHasPermission($user, self::PAYMENT_APPROVAL_PERMISSION, (int) $document->organization_id);
            
            // Проверка прав администратора/владельца организации
            $isAdmin = $canApprovePayments || $this->isPrivilegedApprovalActor($user, (int) $document->organization_id);
            if ($user) {
                $organizationId = $document->organization_id;

                Log::info('payment_approval.auth_check', [
                    'user_id' => $userId,
                    'document_id' => $document->id,
                    'organization_id' => $organizationId,
                    'check' => self::PAYMENT_APPROVAL_PERMISSION,
                    'result' => $isAdmin,
                ]);
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
                            'approval_role' => null,
                            'approval_permission' => self::PAYMENT_APPROVAL_PERMISSION,
                            'approver_user_id' => $userId,
                            'approval_level' => 1,
                            'approval_order' => 1,
                            'status' => 'approved',
                            'decision_comment' => 'Утверждено администратором/владельцем организации',
                            'decided_at' => now(),
                        ]);
                        
                        $this->budgetLimitService->assertAllowed(
                            $document,
                            PaymentBudgetLimitService::OPERATION_APPROVAL,
                            (float) $document->amount,
                            $user,
                            $budgetOverrideReason
                        );
                        $this->stateMachine->approve($document, $userId);
                        $this->budgetLimitService->syncReservation($document->fresh(), $user, $budgetOverrideReason);
                        DB::commit();
                        return true;
                    } else {
                        Log::warning('payment_approval.admin_cannot_approve_wrong_status', [
                            'user_id' => $userId,
                            'document_id' => $document->id,
                            'status' => $document->status->value,
                        ]);
                        throw new \DomainException(sprintf(
                            trans_message('payments.validation.approval_not_required_for_status'),
                            $document->status->label()
                        ));
                    }
                }
                
                Log::warning('payment_approval.no_rights', [
                    'user_id' => $userId,
                    'document_id' => $document->id,
                    'is_admin' => $isAdmin,
                    'has_approval' => false,
                ]);
                throw new \DomainException(trans_message('payments.validation.approval_forbidden'));
            }

            // Проверка лимита суммы (пропускаем для админов)
            if (!$isAdmin && !$approval->canApproveAmount($document->amount)) {
                throw new \DomainException(trans_message('payments.validation.approval_limit_exceeded'));
            }

            // Проверить условия (пропускаем для админов)
            if (!$isAdmin && !$approval->checkConditions($document)) {
                throw new \DomainException(trans_message('payments.validation.approval_conditions_invalid'));
            }

            // Утвердить конкретный шаг
            $approval->approver_user_id = $userId;
            $approval->status = 'approved';
            $approval->decision_comment = $comment;
            $approval->decided_at = now();
            $approval->save();

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
                $this->budgetLimitService->assertAllowed(
                    $document,
                    PaymentBudgetLimitService::OPERATION_APPROVAL,
                    (float) $document->amount,
                    $user,
                    $budgetOverrideReason
                );
                $this->stateMachine->approve($document, $userId);
                $this->budgetLimitService->syncReservation($document->fresh(), $user, $budgetOverrideReason);
                
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

            if (in_array($document->status, [PaymentDocumentStatus::REJECTED, PaymentDocumentStatus::CANCELLED])) {
                DB::commit();
                return true; // Уже отклонен или отменен
            }

            // Найти pending утверждение для данного пользователя
            $user = User::find($userId);
            $approval = $this->approvalQueryForUser($document, $userId, $user)
                ->lockForUpdate()
                ->first();
            $approval ??= $this->legacyRoleApprovalForUser($document, $userId);

            if (!$approval) {
                throw new \DomainException(trans_message('payments.validation.approval_reject_forbidden'));
            }

            $approval->approver_user_id = $userId;
            $approval->reject($reason);

            // Отменить все остальные pending утверждения
            PaymentApproval::where('payment_document_id', $document->id)
                ->where('status', 'pending')
                ->update(['status' => 'skipped']);

            // Изменить статус документа
            $this->stateMachine->reject($document, $reason);
            $this->budgetLimitService->release($document, $reason);

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
                $isAdmin = $this->isPrivilegedApprovalActor($user, $organizationId)
                    || $this->userHasPermission($user, self::PAYMENT_APPROVAL_PERMISSION, $organizationId);
            }
        }

        $query = PaymentApproval::with(['paymentDocument', 'organization'])
            ->where('status', 'pending');
            
        if ($isAdmin && $organizationId) {
            // Для админа показываем:
            // 1. Те, что назначены лично ему
            // 2. Те, что не назначены никому (но подходят по ролям)
            // 3. НО не показываем "чужие" задачи, если админ не перешел в спец. режим
            $query->where('organization_id', $organizationId)
                ->where(function($q) use ($userId) {
                    $q->where('approver_user_id', $userId)
                      ->orWhere(function ($permissionScope): void {
                          $permissionScope->whereNull('approver_user_id')
                              ->where('approval_permission', self::PAYMENT_APPROVAL_PERMISSION);
                      });
                });
            
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
                        'approval_role' => null,
                        'approval_permission' => self::PAYMENT_APPROVAL_PERMISSION,
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
    public function getApprovalStatus(PaymentDocument $document, ?int $userId = null): array
    {
        // Принудительно очищаем связи, чтобы получить свежие данные с именами
        $document->unsetRelation('approvals');
        $approvals = $this->getApprovalHistory($document);
        
        $total = $approvals->count();
        $approved = $approvals->where('status', 'approved')->count();
        $rejected = $approvals->where('status', 'rejected')->count();
        $pending = $approvals->where('status', 'pending')->count();

        // Если документ утвержден, но нет записей утверждения - создаем ретроспективную запись
        if ($document->status === PaymentDocumentStatus::APPROVED && $total === 0) {
            $approvedByUserId = $document->approved_by_user_id;
            
            // Создаем запись для истории (ретроспективно)
            PaymentApproval::create([
                'payment_document_id' => $document->id,
                'organization_id' => $document->organization_id,
                'approval_role' => null,
                'approval_permission' => self::PAYMENT_APPROVAL_PERMISSION,
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

        // Если документ на утверждении, но нет записей - создаем дефолтную запись для администраторов
        if ($document->status === PaymentDocumentStatus::PENDING_APPROVAL && $total === 0) {
            PaymentApproval::create([
                'payment_document_id' => $document->id,
                'organization_id' => $document->organization_id,
                'approval_role' => null,
                'approval_permission' => self::PAYMENT_APPROVAL_PERMISSION,
                'approver_user_id' => null, // Может утвердить любой админ
                'approval_level' => 1,
                'approval_order' => 1,
                'status' => 'pending',
                'decision_comment' => null,
            ]);
            
            // Перезагружаем approvals
            $approvals = $this->getApprovalHistory($document);
            $total = $approvals->count();
            $pending = $approvals->where('status', 'pending')->count();
        }

        $currentLevel = null;
        if ($pending > 0) {
            $firstPending = $approvals->where('status', 'pending')->first();
            $currentLevel = $firstPending?->approval_level;
        }

        // Для определения полной утвержденности смотрим ТОЛЬКО на шаги
        $isFullyApproved = $pending === 0 && $rejected === 0 && $total > 0;

        $canBeApprovedByCurrentUser = false;
        if ($userId && $document->status === PaymentDocumentStatus::PENDING_APPROVAL) {
            $user = User::find($userId);
            if ($user) {
                // Проверяем админские права
                if ($this->isPrivilegedApprovalActor($user, (int) $document->organization_id)) {
                    $canBeApprovedByCurrentUser = true;
                } else {
                    // Ищем pending approvals, которые может утвердить этот юзер
                    $pendingApprovals = $approvals->where('status', 'pending');
                    
                    // Чтобы разрешить параллельное утверждение, мы не привязываемся строго к currentLevel
                    // (но в строгих системах может быть только $pendingApprovals->where('approval_level', $currentLevel))
                    
                    foreach ($pendingApprovals as $approval) {
                        // Если назначен конкретному пользователю
                        if ($approval->approver_user_id === $userId) {
                            $canBeApprovedByCurrentUser = true;
                            break;
                        }
                        
                        if ($approval->approval_permission) {
                            if ($this->userHasPermission($user, $approval->approval_permission, (int) $document->organization_id)) {
                                $canBeApprovedByCurrentUser = true;
                                break;
                            }
                        } elseif ($approval->approval_role) {
                            $context = \App\Domain\Authorization\Models\AuthorizationContext::getOrganizationContext($document->organization_id);
                            $hasRole = User::where('id', $userId)
                                ->whereHas('roleAssignments', function ($query) use ($context, $approval) {
                                    $query->where('context_id', $context->id)
                                          ->where('role_slug', $approval->approval_role);
                                })->exists();
                                
                            if ($hasRole) {
                                $canBeApprovedByCurrentUser = true;
                                break;
                            }
                        }
                    }
                }
            }
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
            'can_be_approved_by_current_user' => $canBeApprovedByCurrentUser,
            'pending_approvals' => $approvals->where('status', 'pending')->values()->map(fn($a) => [
                'id' => $a->id,
                'role' => $a->approval_role,
                'role_label' => $a->getRoleLabel(),
                'approval_permission' => $a->approval_permission,
                'approval_permission_label' => $a->getPermissionLabel(),
                'approver' => $a->approver?->name,
                'level' => $a->approval_level,
                'order' => $a->approval_order,
                'status' => $a->status,
                'status_label' => $a->getStatusLabel(),
                'comment' => $a->decision_comment,
                'decided_at' => $a->decided_at?->toDateTimeString(),
            ]),
            'approvals' => $approvals->map(fn($a) => [
                'id' => $a->id,
                'role' => $a->approval_role,
                'role_label' => $a->getRoleLabel(),
                'approval_permission' => $a->approval_permission,
                'approval_permission_label' => $a->getPermissionLabel(),
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
            $this->budgetLimitService->assertAllowed(
                $document,
                PaymentBudgetLimitService::OPERATION_APPROVAL,
                (float) $document->amount
            );
            $this->stateMachine->approve($document);
            $this->budgetLimitService->syncReservation($document->fresh());
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

