<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Models\PaymentApproval;
use App\BusinessModules\Core\Payments\Services\ApprovalWorkflowService;
use App\BusinessModules\Core\Payments\Services\PaymentDocumentPresenter;
use App\BusinessModules\Core\Payments\Services\PaymentDocumentQueryService;
use App\BusinessModules\Core\Payments\Services\PaymentDocumentWorkflowService;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class MobilePaymentDocumentService
{
    public function __construct(
        private readonly PaymentDocumentQueryService $queries,
        private readonly PaymentDocumentWorkflowService $workflow,
        private readonly PaymentDocumentPresenter $presenter,
        private readonly MobileProjectAccessResolver $projectAccess,
        private readonly ApprovalWorkflowService $approvals,
    ) {}

    /** @return array<string, mixed> */
    public function index(int $organizationId, User $user, array $filters): array
    {
        $projectIds = $this->visibleProjectIds($organizationId, $user, $filters['project_id'] ?? null);
        $hasOrganizationPermission = $this->canViewPayment($user, $organizationId);
        if ($projectIds === [] && ! $hasOrganizationPermission) {
            throw new \DomainException(trans_message('auth.mobile_access_denied'), 403);
        }

        $page = $this->queries->listForOrganization($organizationId, [
            'project_id' => $filters['project_id'] ?? null,
            'project_ids' => $projectIds,
            'include_projectless' => ! isset($filters['project_id']) && $hasOrganizationPermission,
            'status' => $filters['status'] ?? null,
            'search' => $filters['search'] ?? null,
            'page' => $filters['page'] ?? 1,
            'per_page' => min(50, (int) ($filters['per_page'] ?? 20)),
        ]);

        return [
            'items' => collect($page->items())->map(fn (PaymentDocument $document): array => $this->presenter->brief($document, $user))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
                'capabilities' => ['can_create' => $this->canCreateForVisibleProjects(
                    $user,
                    $organizationId,
                    $filters['project_id'] ?? null,
                    $projectIds
                )],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function show(int $organizationId, User $user, int $id): array
    {
        $document = $this->queries->findDetailed($organizationId, $id);
        $this->assertCanAccessDocument($user, $document, 'payments.invoice.view');

        return $this->withCapabilities($this->presenter->detailed($document, $user), $user, $document);
    }

    /** @return array<string, mixed> */
    public function create(int $organizationId, User $user, array $data): array
    {
        $projectId = isset($data['project_id']) ? (int) $data['project_id'] : null;
        $this->assertPermission($user, 'payments.invoice.create', $organizationId, $projectId);
        if ($projectId !== null) {
            $this->assertProjectAccess($user, $organizationId, $projectId);
        }
        $key = (string) $data['idempotency_key'];
        unset($data['idempotency_key']);
        $payloadHash = hash('sha256', json_encode($this->canonicalPayload($data), JSON_THROW_ON_ERROR));
        $data['origin_key'] = 'mobile:'.$user->id.':'.$key;
        $data['mobile_payload_hash'] = $payloadHash;

        [$document, $created, $warnings] = DB::transaction(function () use ($organizationId, $key, $data, $user): array {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ["mobile-payment-create:{$organizationId}:{$key}"]);
            }
            $existing = PaymentDocument::query()
                ->where('organization_id', $organizationId)
                ->where('origin_key', $data['origin_key'])
                ->first();
            if ($existing) {
                if ($existing->mobile_payload_hash !== $data['mobile_payload_hash']) {
                    throw new \DomainException(trans_message('payments.validation.idempotency_conflict'), 409);
                }
                $existing = $this->queries->findDetailed($organizationId, (int) $existing->id);
                $this->assertCanAccessDocument($user, $existing, 'payments.invoice.create');

                return [$existing, false, []];
            }
            $result = $this->workflow->create($organizationId, (int) $user->id, $data);
            if ($result['document']->mobile_payload_hash !== $data['mobile_payload_hash']) {
                throw new \DomainException(trans_message('payments.validation.idempotency_conflict'), 409);
            }
            $this->assertCanAccessDocument($user, $result['document'], 'payments.invoice.create');

            return [$result['document'], true, $result['warnings']];
        });

        return [
            'data' => $this->withCapabilities(
                array_merge($this->presenter->detailed($document, $user), ['warnings' => $warnings]),
                $user,
                $document
            ),
            'created' => $created,
        ];
    }

    /** @return array<string, mixed> */
    public function update(int $organizationId, User $user, int $id, array $data): array
    {
        $document = $this->queries->findForWorkflow($organizationId, $id);
        $this->assertCanAccessDocument($user, $document, 'payments.invoice.edit');
        if (array_key_exists('project_id', $data)) {
            $targetProjectId = $data['project_id'] === null ? null : (int) $data['project_id'];
            if ($targetProjectId === null) {
                $this->assertPermission($user, 'payments.invoice.edit', $organizationId);
            } else {
                $this->assertProjectAccess($user, $organizationId, $targetProjectId);
                $this->assertPermission($user, 'payments.invoice.edit', $organizationId, $targetProjectId);
            }
        }
        $this->workflow->update($document, $data);

        return $this->show($organizationId, $user, $id);
    }

    /** @return array<string, mixed> */
    public function submit(int $organizationId, User $user, int $id, ?string $budgetOverrideReason): array
    {
        $document = $this->queries->findForWorkflow($organizationId, $id);
        $this->assertCanAccessDocument($user, $document, 'payments.invoice.issue');
        $submitted = $this->workflow->submit($document, $user, $budgetOverrideReason);

        return $this->withCapabilities($this->presenter->detailed($submitted, $user), $user, $submitted);
    }

    /** @return array<string, mixed> */
    public function registerPayment(int $organizationId, User $user, int $id, array $data): array
    {
        $document = $this->queries->findForWorkflow($organizationId, $id);
        $this->assertCanAccessDocument($user, $document, 'payments.transaction.register');
        $paid = $this->workflow->registerPayment($document, (int) $user->id, $data);

        return $this->withCapabilities($this->presenter->detailed($paid, $user), $user, $paid);
    }

    public function approve(int $organizationId, User $user, int $id, array $data): array
    {
        $document = $this->queries->findForWorkflow($organizationId, $id);
        $this->assertCanAccessDocument($user, $document, 'payments.transaction.approve');
        $this->assertAssignedPendingApproval($document, $user, 'payments.transaction.approve');
        $this->approvals->approveByUser($document, (int) $user->id, $data['comment'] ?? null, $data['budget_override_reason'] ?? null);

        return $this->show($organizationId, $user, $id);
    }

    public function reject(int $organizationId, User $user, int $id, array $data): array
    {
        $document = $this->queries->findForWorkflow($organizationId, $id);
        $this->assertCanAccessDocument($user, $document, 'payments.transaction.reject');
        $this->assertAssignedPendingApproval($document, $user, 'payments.transaction.reject');
        $this->approvals->rejectByUser($document, (int) $user->id, $data['reason']);

        return $this->show($organizationId, $user, $id);
    }

    private function assertAssignedPendingApproval(PaymentDocument $document, User $user, string $decisionPermission): void
    {
        if (! $this->hasPendingApprovalForUser($document, $user, $decisionPermission)) {
            throw new \DomainException(trans_message('payments.validation.approval_forbidden'), 403);
        }
    }

    private function hasPendingApprovalForUser(PaymentDocument $document, User $user, string $decisionPermission): bool
    {
        $canClaimPermissionApproval = $this->canAccessDocument($user, $document, $decisionPermission);

        return PaymentApproval::query()
            ->where('organization_id', $document->organization_id)
            ->where('payment_document_id', $document->id)
            ->where('status', 'pending')
            ->where(static function ($query) use ($user, $canClaimPermissionApproval): void {
                $query->where('approver_user_id', $user->id);
                if ($canClaimPermissionApproval) {
                    $query->orWhere(static function ($permissionQuery): void {
                        $permissionQuery->whereNull('approver_user_id')
                            ->where('approval_permission', 'payments.transaction.approve');
                    });
                }
            })
            ->exists();
    }

    public function canAccessDocument(User $user, PaymentDocument $document, string $permission = 'payments.invoice.view'): bool
    {
        $organizationId = (int) $document->organization_id;
        if ($organizationId <= 0) {
            return false;
        }
        $projectId = $document->project_id === null ? null : (int) $document->project_id;
        if ($projectId === null) {
            return $this->hasPermission($user, $permission, ['organization_id' => $organizationId]);
        }

        if (! $this->projectAccess->query($user, $organizationId)->whereKey($projectId)->exists()) {
            return false;
        }

        return $this->hasPermission($user, $permission, [
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'strict_project_scope' => true,
        ])
            || $this->hasPermission($user, $permission, ['organization_id' => $organizationId]);
    }

    public function assertCanAccessDocument(User $user, PaymentDocument $document, string $permission = 'payments.invoice.view'): void
    {
        if (! $this->canAccessDocument($user, $document, $permission)) {
            throw new \DomainException(trans_message('payments.not_found'), 404);
        }
    }

    private function visibleProjectIds(int $organizationId, User $user, mixed $selectedProjectId): array
    {
        $accessible = $this->projectAccess->ids($user, $organizationId);
        $hasOrganizationPermission = $this->canViewPayment($user, $organizationId);
        if ($selectedProjectId === null) {
            if ($hasOrganizationPermission) {
                return $accessible;
            }

            return array_values(array_filter($accessible, fn (int $projectId): bool => $this->canViewPayment(
                $user,
                $organizationId,
                $projectId
            )));
        }

        $projectId = (int) $selectedProjectId;
        if (! in_array($projectId, $accessible, true)) {
            throw new \DomainException(trans_message('payments.not_found'), 404);
        }
        $this->assertPermission($user, 'payments.invoice.view', $organizationId, $projectId);

        return [$projectId];
    }

    private function canonicalPayload(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->canonicalPayload($value);
            }
        }
        if (! array_is_list($payload)) {
            ksort($payload);
        }

        return $payload;
    }

    private function assertProjectAccess(User $user, int $organizationId, int $projectId): void
    {
        try {
            $this->projectAccess->assert($user, $organizationId, $projectId, trans_message('payments.not_found'));
        } catch (\DomainException $exception) {
            throw new \DomainException(trans_message('payments.not_found'), 404, $exception);
        }
    }

    private function assertPermission(User $user, string $permission, int $organizationId, ?int $projectId = null): void
    {
        $context = ['organization_id' => $organizationId];
        if ($projectId !== null) {
            $context['project_id'] = $projectId;
            $context['strict_project_scope'] = true;
        }
        if (! $this->hasPermission($user, $permission, $context)
            && ! ($projectId !== null && $this->hasPermission($user, $permission, ['organization_id' => $organizationId]))) {
            throw new \DomainException(trans_message('auth.mobile_access_denied'), 403);
        }
    }

    /** @param array<string, int|bool> $context */
    private function hasPermission(User $user, string $permission, array $context): bool
    {
        if ($user->can($permission, $context)) {
            return true;
        }

        return $permission === 'payments.invoice.view'
            && $user->can('payments.invoice.view_all', $context);
    }

    private function canViewPayment(User $user, int $organizationId, ?int $projectId = null): bool
    {
        $context = ['organization_id' => $organizationId];
        if ($projectId !== null) {
            $context['project_id'] = $projectId;
            $context['strict_project_scope'] = true;
        }

        return $this->hasPermission($user, 'payments.invoice.view', $context);
    }

    /** @param list<int> $projectIds */
    private function canCreateForVisibleProjects(User $user, int $organizationId, mixed $selectedProjectId, array $projectIds): bool
    {
        if ($selectedProjectId !== null) {
            return $this->hasPermission($user, 'payments.invoice.create', [
                'organization_id' => $organizationId,
                'project_id' => (int) $selectedProjectId,
                'strict_project_scope' => true,
            ]) || $user->can('payments.invoice.create', ['organization_id' => $organizationId]);
        }

        if ($user->can('payments.invoice.create', ['organization_id' => $organizationId])) {
            return true;
        }

        foreach ($projectIds as $projectId) {
            if ($this->hasPermission($user, 'payments.invoice.create', [
                'organization_id' => $organizationId,
                'project_id' => $projectId,
                'strict_project_scope' => true,
            ])) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function withCapabilities(array $data, User $user, PaymentDocument $document): array
    {
        $context = ['organization_id' => (int) $document->organization_id];
        if ($document->project_id !== null) {
            $context['project_id'] = (int) $document->project_id;
            $context['strict_project_scope'] = true;
        }
        $isDraft = $document->status->value === 'draft';
        $data['can_edit'] = $isDraft && $user->can('payments.invoice.edit', $context);
        $data['can_submit'] = $isDraft && $user->can('payments.invoice.issue', $context);
        $data['can_register_payment'] = $document->canBePaid() && $user->can('payments.transaction.register', $context);
        $data['can_approve'] = $this->canAccessDocument($user, $document, 'payments.transaction.approve')
            && $this->hasPendingApprovalForUser($document, $user, 'payments.transaction.approve');
        $data['can_reject'] = $this->canAccessDocument($user, $document, 'payments.transaction.reject')
            && $this->hasPendingApprovalForUser($document, $user, 'payments.transaction.reject');
        $data['capabilities'] = [
            'can_edit' => $data['can_edit'],
            'can_submit' => $data['can_submit'],
            'can_register_payment' => $data['can_register_payment'],
            'can_approve' => $data['can_approve'],
            'can_reject' => $data['can_reject'],
        ];

        return $data;
    }
}
