<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ChangeManagement\Services;

use App\BusinessModules\Core\Reporting\Support\ExactDecimal;
use App\BusinessModules\Features\ChangeManagement\Models\ChangeApproval;
use App\BusinessModules\Features\ChangeManagement\Models\ChangeClaim;
use App\BusinessModules\Features\ChangeManagement\Models\ChangeManagementRfi;
use App\BusinessModules\Features\ChangeManagement\Models\ChangeRequest;
use App\BusinessModules\Features\ChangeManagement\Models\VariationOrder;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Services\ChangeWorkflowEventRecorder;
use App\Models\ContractProjectAllocation;
use App\Models\Project;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class ChangeManagementService
{
    public function __construct(private readonly ChangeWorkflowEventRecorder $changeEvents) {}

    public function paginateRfis(int $organizationId, int $perPage, array $filters = []): LengthAwarePaginator
    {
        return ChangeManagementRfi::query()
            ->forOrganization($organizationId)
            ->when($filters['project_id'] ?? null, static fn ($query, $projectId) => $query->where('project_id', $projectId))
            ->when($filters['status'] ?? null, static fn ($query, $status) => $query->where('status', $status))
            ->latest('id')
            ->paginate($perPage);
    }

    public function paginateChanges(int $organizationId, int $perPage, array $filters = []): LengthAwarePaginator
    {
        return ChangeRequest::query()
            ->with(['impact', 'approvals', 'variationOrders', 'relatedRfi'])
            ->forOrganization($organizationId)
            ->when($filters['project_id'] ?? null, static fn ($query, $projectId) => $query->where('project_id', $projectId))
            ->when($filters['status'] ?? null, static fn ($query, $status) => $query->where('status', $status))
            ->latest('id')
            ->paginate($perPage);
    }

    public function paginateClaims(int $organizationId, int $perPage, array $filters = []): LengthAwarePaginator
    {
        return ChangeClaim::query()
            ->with(['changeRequest'])
            ->forOrganization($organizationId)
            ->when($filters['project_id'] ?? null, static fn ($query, $projectId) => $query->where('project_id', $projectId))
            ->when($filters['status'] ?? null, static fn ($query, $status) => $query->where('status', $status))
            ->latest('id')
            ->paginate($perPage);
    }

    public function createRfi(int $organizationId, int $userId, array $data): ChangeManagementRfi
    {
        $this->assertProjectInOrganization((int) $data['project_id'], $organizationId);

        return ChangeManagementRfi::create([
            'organization_id' => $organizationId,
            'project_id' => (int) $data['project_id'],
            'created_by_user_id' => $userId,
            'rfi_number' => $data['rfi_number'] ?? $this->nextNumber(ChangeManagementRfi::class, $organizationId, 'RFI', 'rfi_number'),
            'subject' => $data['subject'],
            'question' => $data['question'],
            'addressee_type' => $data['addressee_type'],
            'status' => 'draft',
            'response_due_date' => $data['response_due_date'] ?? null,
            'attachments' => $data['attachments'] ?? [],
            'metadata' => $data['metadata'] ?? [],
        ]);
    }

    public function findRfi(int $organizationId, int $id): ChangeManagementRfi
    {
        $rfi = ChangeManagementRfi::query()
            ->forOrganization($organizationId)
            ->find($id);

        if (! $rfi instanceof ChangeManagementRfi) {
            throw new DomainException(trans_message('change_management.errors.rfi_not_found'));
        }

        return $rfi;
    }

    public function sendRfi(ChangeManagementRfi $rfi): ChangeManagementRfi
    {
        $this->assertStatus($rfi->status, ['draft']);

        $rfi->forceFill([
            'status' => 'sent',
            'sent_at' => now(),
        ])->save();

        return $rfi->refresh();
    }

    public function answerRfi(ChangeManagementRfi $rfi, string $answer): ChangeManagementRfi
    {
        $this->assertStatus($rfi->status, ['sent', 'overdue', 'clarification_requested']);

        $rfi->forceFill([
            'status' => 'answered',
            'answer' => $answer,
            'answered_at' => now(),
        ])->save();

        return $rfi->refresh();
    }

    public function acceptRfi(ChangeManagementRfi $rfi): ChangeManagementRfi
    {
        $this->assertStatus($rfi->status, ['answered']);

        $rfi->forceFill([
            'status' => 'accepted',
            'accepted_at' => now(),
        ])->save();

        return $rfi->refresh();
    }

    public function createChange(int $organizationId, int $userId, array $data): ChangeRequest
    {
        $this->assertProjectInOrganization((int) $data['project_id'], $organizationId);
        $monetary = $data['monetary_context'];
        $allocationId = (int) $monetary['contract_project_allocation_id'];
        $allocationExists = ContractProjectAllocation::query()
            ->whereKey($allocationId)
            ->where('project_id', (int) $data['project_id'])
            ->whereHas('contract', static fn ($query) => $query->where('organization_id', $organizationId))
            ->exists();
        if (! $allocationExists) {
            throw new DomainException(trans_message('change_management.errors.project_scope'));
        }

        if (($data['related_rfi_id'] ?? null) !== null) {
            $this->findRfi($organizationId, (int) $data['related_rfi_id']);
        }

        return DB::transaction(function () use (
            $organizationId,
            $userId,
            $data,
            $monetary,
            $allocationId,
        ): ChangeRequest {
            $change = ChangeRequest::create([
                'organization_id' => $organizationId,
                'project_id' => (int) $data['project_id'],
                'created_by_user_id' => $userId,
                'related_rfi_id' => $data['related_rfi_id'] ?? null,
                'change_number' => $data['change_number'] ?? $this->nextNumber(ChangeRequest::class, $organizationId, 'CHG', 'change_number'),
                'title' => $data['title'],
                'reason' => $data['reason'],
                'description' => $data['description'],
                'initiator_type' => $data['initiator_type'],
                'status' => 'draft',
                'affected_schedule_task_ids' => $this->integerList($data['affected_schedule_task_ids'] ?? []),
                'affected_estimate_item_ids' => $this->integerList($data['affected_estimate_item_ids'] ?? []),
                'linked_entities' => $data['linked_entities'] ?? [],
                'reporting_currency' => mb_strtoupper((string) $monetary['currency']),
                'reporting_contract_project_allocation_id' => $allocationId,
                'contingency_opening_minor' => ExactDecimal::minor((string) $monetary['contingency_opening_amount']),
                'contingency_allocation_minor' => ExactDecimal::minor((string) $monetary['contingency_allocation_amount']),
                'contingency_release_minor' => ExactDecimal::minor((string) $monetary['contingency_release_amount']),
            ]);
            $this->changeEvents->record($change, 'create', CarbonImmutable::now(), $userId);

            return $change->load(['impact', 'approvals', 'variationOrders', 'relatedRfi']);
        });
    }

    public function findChange(int $organizationId, int $id): ChangeRequest
    {
        $change = ChangeRequest::query()
            ->with(['impact', 'approvals', 'variationOrders', 'relatedRfi'])
            ->forOrganization($organizationId)
            ->find($id);

        if (! $change instanceof ChangeRequest) {
            throw new DomainException(trans_message('change_management.errors.change_not_found'));
        }

        return $change;
    }

    public function submitChange(ChangeRequest $change): ChangeRequest
    {
        return $this->lockedTransition(
            $change,
            ['draft'],
            'submitted',
            function (ChangeRequest $lockedChange): void {
                $lockedChange->forceFill([
                    'status' => 'submitted',
                    'submitted_at' => now(),
                ])->save();
                $this->changeEvents->record($lockedChange, 'submit', CarbonImmutable::now(), null);
            },
        );
    }

    public function assessImpact(ChangeRequest $change, array $data): ChangeRequest
    {
        return $this->lockedTransition(
            $change,
            ['submitted', 'impact_assessment'],
            'impact_assessment',
            function (ChangeRequest $lockedChange) use ($data): void {
                $impactData = [
                    'organization_id' => $lockedChange->organization_id,
                    'cost_delta' => $data['cost_delta'] ?? 0,
                    'schedule_delta_days' => $data['schedule_delta_days'] ?? 0,
                    'requires_contract_change' => (bool) ($data['requires_contract_change'] ?? false),
                    'requires_estimate_revision' => (bool) ($data['requires_estimate_revision'] ?? false),
                    'requires_procurement_update' => (bool) ($data['requires_procurement_update'] ?? false),
                    'requires_customer_approval' => (bool) ($data['requires_customer_approval'] ?? false),
                    'affected_schedule_task_ids' => $this->integerList($data['affected_schedule_task_ids'] ?? $lockedChange->affected_schedule_task_ids ?? []),
                    'affected_estimate_item_ids' => $this->integerList($data['affected_estimate_item_ids'] ?? $lockedChange->affected_estimate_item_ids ?? []),
                    'affected_contract_ids' => $this->integerList($data['affected_contract_ids'] ?? []),
                    'summary' => $data['summary'] ?? null,
                ];

                $lockedChange->impact()->updateOrCreate([], $impactData);
                $lockedChange->forceFill([
                    'status' => 'impact_assessment',
                    'affected_schedule_task_ids' => $impactData['affected_schedule_task_ids'],
                    'affected_estimate_item_ids' => $impactData['affected_estimate_item_ids'],
                ])->save();
                $this->changeEvents->record($lockedChange, 'impact_assessment', CarbonImmutable::now(), null);
            },
            fn (ChangeRequest $lockedChange): bool => $this->impactMatches($lockedChange, $data),
        );
    }

    public function startInternalReview(ChangeRequest $change): ChangeRequest
    {
        return $this->lockedTransition(
            $change,
            ['impact_assessment'],
            'internal_review',
            function (ChangeRequest $lockedChange): void {
                $this->assertImpactExists($lockedChange);
                $lockedChange->forceFill(['status' => 'internal_review'])->save();
                $this->changeEvents->record($lockedChange, 'review', CarbonImmutable::now(), null);
            },
        );
    }

    public function startCustomerReview(ChangeRequest $change): ChangeRequest
    {
        return $this->lockedTransition(
            $change,
            ['internal_review'],
            'customer_review',
            function (ChangeRequest $lockedChange): void {
                $impact = $this->assertImpactExists($lockedChange);
                if (! $impact->requires_customer_approval) {
                    throw new DomainException(trans_message('change_management.errors.customer_approval_not_required'));
                }
                $lockedChange->forceFill(['status' => 'customer_review'])->save();
                $this->changeEvents->record($lockedChange, 'review', CarbonImmutable::now(), null);
            },
        );
    }

    public function approveChange(
        ChangeRequest $change,
        int $userId,
        string $approvedCostAmount,
        ?string $comment = null,
    ): ChangeRequest {
        return $this->approve($change, $userId, 'internal', $approvedCostAmount, $comment);
    }

    public function customerApprove(
        ChangeRequest $change,
        int $userId,
        string $approvedCostAmount,
        ?string $comment = null,
    ): ChangeRequest {
        return $this->approve($change, $userId, 'customer', $approvedCostAmount, $comment);
    }

    public function createVariationOrder(ChangeRequest $change, array $data): VariationOrder
    {
        return DB::transaction(function () use ($change, $data): VariationOrder {
            $lockedChange = ChangeRequest::query()->whereKey($change->id)->lockForUpdate()->firstOrFail();
            if (! in_array($lockedChange->status, ['approved', 'implemented', 'closed'], true)) {
                throw new DomainException(trans_message('change_management.errors.variation_requires_approved_change'));
            }

            $variationNumber = trim((string) $data['variation_number']);
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [
                    "variation:{$lockedChange->organization_id}:{$variationNumber}",
                ]);
            }

            $amount = (string) ($data['amount'] ?? $lockedChange->impact?->cost_delta ?? 0);
            $amountMinor = ExactDecimal::minor($amount);
            if ($amountMinor < 0) {
                throw new DomainException(trans_message('change_management.errors.variation_amount_invalid'));
            }

            $existing = VariationOrder::query()
                ->where('organization_id', $lockedChange->organization_id)
                ->where('variation_number', $variationNumber)
                ->first();
            if ($existing instanceof VariationOrder) {
                if ((int) $existing->change_request_id !== (int) $lockedChange->id
                    || ExactDecimal::minor((string) $existing->amount) !== $amountMinor
                    || (int) $existing->schedule_delta_days !== (int) ($data['schedule_delta_days'] ?? $lockedChange->impact?->schedule_delta_days ?? 0)
                    || (string) ($existing->description ?? '') !== (string) ($data['description'] ?? '')) {
                    throw new DomainException(trans_message('change_management.errors.variation_idempotency_conflict'));
                }

                return $existing;
            }

            $approval = ChangeApproval::query()
                ->where('change_request_id', $lockedChange->id)
                ->where('status', 'approved')
                ->whereNotNull('approved_cost_minor')
                ->latest('decided_at')
                ->latest('id')
                ->first();
            if (! $approval instanceof ChangeApproval) {
                throw new DomainException(trans_message('change_management.errors.monetary_context_missing'));
            }

            $allocatedMinor = VariationOrder::query()
                ->where('change_request_id', $lockedChange->id)
                ->lockForUpdate()
                ->get(['amount'])
                ->sum(static fn (VariationOrder $order): int => ExactDecimal::minor((string) $order->amount));
            if ($allocatedMinor + $amountMinor > (int) $approval->approved_cost_minor) {
                throw new DomainException(trans_message('change_management.errors.variation_exceeds_approved_amount'));
            }

            return VariationOrder::query()->create([
                'organization_id' => $lockedChange->organization_id,
                'change_request_id' => $lockedChange->id,
                'variation_number' => $variationNumber,
                'amount' => $amount,
                'schedule_delta_days' => $data['schedule_delta_days'] ?? $lockedChange->impact?->schedule_delta_days ?? 0,
                'description' => $data['description'] ?? null,
            ]);
        }, 3);
    }

    public function implementChange(ChangeRequest $change, ?string $comment = null): ChangeRequest
    {
        return $this->lockedTransition(
            $change,
            ['approved'],
            'implemented',
            function (ChangeRequest $lockedChange) use ($comment): void {
                $lockedChange->forceFill([
                    'status' => 'implemented',
                    'implementation_comment' => $comment,
                    'implemented_at' => now(),
                ])->save();
                $this->changeEvents->record($lockedChange, 'implement', CarbonImmutable::now(), null);
            },
            static fn (ChangeRequest $lockedChange): bool => $lockedChange->implementation_comment === $comment,
        );
    }

    public function closeChange(ChangeRequest $change): ChangeRequest
    {
        return $this->lockedTransition(
            $change,
            ['implemented'],
            'closed',
            function (ChangeRequest $lockedChange): void {
                $lockedChange->forceFill([
                    'status' => 'closed',
                    'closed_at' => now(),
                ])->save();
                $this->changeEvents->record($lockedChange, 'close', CarbonImmutable::now(), null);
            },
            invalidStatusMessage: 'change_management.errors.implementation_required',
        );
    }

    public function createClaim(int $organizationId, int $userId, array $data): ChangeClaim
    {
        $this->assertProjectInOrganization((int) $data['project_id'], $organizationId);

        if (($data['change_request_id'] ?? null) !== null) {
            $change = $this->findChange($organizationId, (int) $data['change_request_id']);

            if ((int) $change->project_id !== (int) $data['project_id']) {
                throw new DomainException(trans_message('change_management.errors.project_scope'));
            }
        }

        return DB::transaction(function () use ($organizationId, $userId, $data): ChangeClaim {
            $claim = ChangeClaim::create([
                'organization_id' => $organizationId,
                'project_id' => (int) $data['project_id'],
                'change_request_id' => $data['change_request_id'] ?? null,
                'created_by_user_id' => $userId,
                'claim_number' => $data['claim_number'] ?? $this->nextNumber(ChangeClaim::class, $organizationId, 'CL', 'claim_number'),
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'amount' => $data['amount'] ?? 0,
                'status' => 'submitted',
                'evidence' => $data['evidence'] ?? [],
            ]);
            if ($claim->change_request_id !== null) {
                $change = $this->findChange($organizationId, (int) $claim->change_request_id);
                $this->changeEvents->linkClaim($change, $claim);
            }

            return $claim->load(['changeRequest']);
        });
    }

    private function approve(
        ChangeRequest $change,
        int $userId,
        string $approvalType,
        string $approvedCostAmount,
        ?string $comment,
    ): ChangeRequest {
        $approvedCostMinor = ExactDecimal::minor($approvedCostAmount);

        return DB::transaction(function () use (
            $change,
            $userId,
            $approvalType,
            $approvedCostMinor,
            $comment,
        ): ChangeRequest {
            $lockedChange = ChangeRequest::query()
                ->where('organization_id', $change->organization_id)
                ->whereKey($change->id)
                ->lockForUpdate()
                ->firstOrFail();
            $expectedStatus = $approvalType === 'customer' ? 'customer_review' : 'internal_review';
            if ($lockedChange->status === 'approved') {
                $existing = $lockedChange->approvals()
                    ->where('approval_type', $approvalType)
                    ->where('status', 'approved')
                    ->latest('id')
                    ->first();
                if ($existing instanceof ChangeApproval
                    && (int) $existing->approved_by_user_id === $userId
                    && (int) $existing->approved_cost_minor === $approvedCostMinor
                    && (string) $existing->currency === (string) $lockedChange->reporting_currency
                    && $existing->comment === $comment) {
                    return $this->reloadChange($lockedChange);
                }
            }
            $this->assertStatus((string) $lockedChange->status, [$expectedStatus]);
            $impact = $this->assertImpactExists($lockedChange);
            if ($approvalType === 'customer' && ! $impact->requires_customer_approval) {
                throw new DomainException(trans_message('change_management.errors.customer_approval_not_required'));
            }
            if ($approvalType === 'internal' && $impact->requires_customer_approval) {
                throw new DomainException(trans_message('change_management.errors.customer_approval_required'));
            }
            $change = $lockedChange;
            if ($change->reporting_currency === null) {
                throw new DomainException(trans_message('change_management.errors.monetary_context_missing'));
            }
            ChangeApproval::create([
                'organization_id' => $change->organization_id,
                'change_request_id' => $change->id,
                'approved_by_user_id' => $userId,
                'approval_type' => $approvalType,
                'status' => 'approved',
                'comment' => $comment,
                'approved_cost_minor' => $approvedCostMinor,
                'currency' => (string) $change->reporting_currency,
                'decided_at' => now(),
            ]);

            $change->forceFill([
                'status' => 'approved',
                'approved_at' => now(),
            ])->save();
            $this->changeEvents->record($change, 'approve', CarbonImmutable::now(), $userId);

            return $this->reloadChange($change);
        });
    }

    private function assertProjectInOrganization(int $projectId, int $organizationId): void
    {
        $exists = Project::query()
            ->whereKey($projectId)
            ->where('organization_id', $organizationId)
            ->exists();

        if (! $exists) {
            throw new DomainException(trans_message('change_management.errors.project_scope'));
        }
    }

    private function assertStatus(string $currentStatus, array $allowedStatuses): void
    {
        if (! in_array($currentStatus, $allowedStatuses, true)) {
            throw new DomainException(trans_message('change_management.errors.invalid_status'));
        }
    }

    private function lockedTransition(
        ChangeRequest $change,
        array $allowedStatuses,
        string $targetStatus,
        callable $transition,
        ?callable $isConverged = null,
        ?string $invalidStatusMessage = null,
    ): ChangeRequest {
        return DB::transaction(function () use (
            $change,
            $allowedStatuses,
            $targetStatus,
            $transition,
            $isConverged,
            $invalidStatusMessage,
        ): ChangeRequest {
            $lockedChange = ChangeRequest::query()
                ->where('organization_id', $change->organization_id)
                ->whereKey($change->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($lockedChange->status === $targetStatus
                && ($isConverged === null || $isConverged($lockedChange))) {
                return $this->reloadChange($lockedChange);
            }
            if ($invalidStatusMessage !== null
                && ! in_array((string) $lockedChange->status, $allowedStatuses, true)) {
                throw new DomainException(trans_message($invalidStatusMessage));
            }
            $this->assertStatus((string) $lockedChange->status, $allowedStatuses);
            $transition($lockedChange);

            return $this->reloadChange($lockedChange);
        });
    }

    private function impactMatches(ChangeRequest $change, array $data): bool
    {
        $impact = $this->assertImpactExists($change);

        return ExactDecimal::minor((string) $impact->cost_delta)
                === ExactDecimal::minor((string) ($data['cost_delta'] ?? 0))
            && (int) $impact->schedule_delta_days === (int) ($data['schedule_delta_days'] ?? 0)
            && (bool) $impact->requires_contract_change === (bool) ($data['requires_contract_change'] ?? false)
            && (bool) $impact->requires_estimate_revision === (bool) ($data['requires_estimate_revision'] ?? false)
            && (bool) $impact->requires_procurement_update === (bool) ($data['requires_procurement_update'] ?? false)
            && (bool) $impact->requires_customer_approval === (bool) ($data['requires_customer_approval'] ?? false)
            && $this->integerList((array) $impact->affected_schedule_task_ids)
                === $this->integerList($data['affected_schedule_task_ids'] ?? $change->affected_schedule_task_ids ?? [])
            && $this->integerList((array) $impact->affected_estimate_item_ids)
                === $this->integerList($data['affected_estimate_item_ids'] ?? $change->affected_estimate_item_ids ?? [])
            && $this->integerList((array) $impact->affected_contract_ids)
                === $this->integerList($data['affected_contract_ids'] ?? [])
            && $impact->summary === ($data['summary'] ?? null);
    }

    private function assertImpactExists(ChangeRequest $change): object
    {
        $change->loadMissing('impact');

        if ($change->impact === null) {
            throw new DomainException(trans_message('change_management.errors.impact_required'));
        }

        return $change->impact;
    }

    private function reloadChange(ChangeRequest $change): ChangeRequest
    {
        return $change->refresh()->load(['impact', 'approvals', 'variationOrders', 'relatedRfi']);
    }

    private function nextNumber(string $modelClass, int $organizationId, string $prefix, string $column): string
    {
        $count = $modelClass::query()
            ->where('organization_id', $organizationId)
            ->count() + 1;

        return sprintf('%s-%05d', $prefix, $count);
    }

    private function integerList(array $values): array
    {
        return array_values(array_map('intval', Arr::where($values, static fn ($value): bool => $value !== null && $value !== '')));
    }
}
