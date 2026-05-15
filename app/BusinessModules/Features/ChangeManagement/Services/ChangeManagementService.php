<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ChangeManagement\Services;

use App\BusinessModules\Features\ChangeManagement\Models\ChangeApproval;
use App\BusinessModules\Features\ChangeManagement\Models\ChangeClaim;
use App\BusinessModules\Features\ChangeManagement\Models\ChangeManagementRfi;
use App\BusinessModules\Features\ChangeManagement\Models\ChangeRequest;
use App\BusinessModules\Features\ChangeManagement\Models\VariationOrder;
use App\Models\Project;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class ChangeManagementService
{
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

        if (!$rfi instanceof ChangeManagementRfi) {
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

        if (($data['related_rfi_id'] ?? null) !== null) {
            $this->findRfi($organizationId, (int) $data['related_rfi_id']);
        }

        return ChangeRequest::create([
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
        ])->load(['impact', 'approvals', 'variationOrders', 'relatedRfi']);
    }

    public function findChange(int $organizationId, int $id): ChangeRequest
    {
        $change = ChangeRequest::query()
            ->with(['impact', 'approvals', 'variationOrders', 'relatedRfi'])
            ->forOrganization($organizationId)
            ->find($id);

        if (!$change instanceof ChangeRequest) {
            throw new DomainException(trans_message('change_management.errors.change_not_found'));
        }

        return $change;
    }

    public function submitChange(ChangeRequest $change): ChangeRequest
    {
        $this->assertStatus($change->status, ['draft']);

        $change->forceFill([
            'status' => 'submitted',
            'submitted_at' => now(),
        ])->save();

        return $this->reloadChange($change);
    }

    public function assessImpact(ChangeRequest $change, array $data): ChangeRequest
    {
        $this->assertStatus($change->status, ['submitted', 'impact_assessment']);

        return DB::transaction(function () use ($change, $data): ChangeRequest {
            $impactData = [
                'organization_id' => $change->organization_id,
                'cost_delta' => $data['cost_delta'] ?? 0,
                'schedule_delta_days' => $data['schedule_delta_days'] ?? 0,
                'requires_contract_change' => (bool) ($data['requires_contract_change'] ?? false),
                'requires_estimate_revision' => (bool) ($data['requires_estimate_revision'] ?? false),
                'requires_procurement_update' => (bool) ($data['requires_procurement_update'] ?? false),
                'requires_customer_approval' => (bool) ($data['requires_customer_approval'] ?? false),
                'affected_schedule_task_ids' => $this->integerList($data['affected_schedule_task_ids'] ?? $change->affected_schedule_task_ids ?? []),
                'affected_estimate_item_ids' => $this->integerList($data['affected_estimate_item_ids'] ?? $change->affected_estimate_item_ids ?? []),
                'affected_contract_ids' => $this->integerList($data['affected_contract_ids'] ?? []),
                'summary' => $data['summary'] ?? null,
            ];

            $change->impact()->updateOrCreate([], $impactData);

            $change->forceFill([
                'status' => 'impact_assessment',
                'affected_schedule_task_ids' => $impactData['affected_schedule_task_ids'],
                'affected_estimate_item_ids' => $impactData['affected_estimate_item_ids'],
            ])->save();

            return $this->reloadChange($change);
        });
    }

    public function startInternalReview(ChangeRequest $change): ChangeRequest
    {
        $this->assertStatus($change->status, ['impact_assessment']);
        $this->assertImpactExists($change);

        $change->forceFill(['status' => 'internal_review'])->save();

        return $this->reloadChange($change);
    }

    public function startCustomerReview(ChangeRequest $change): ChangeRequest
    {
        $this->assertStatus($change->status, ['internal_review']);
        $impact = $this->assertImpactExists($change);

        if (!$impact->requires_customer_approval) {
            throw new DomainException(trans_message('change_management.errors.customer_approval_not_required'));
        }

        $change->forceFill(['status' => 'customer_review'])->save();

        return $this->reloadChange($change);
    }

    public function approveChange(ChangeRequest $change, int $userId, ?string $comment = null): ChangeRequest
    {
        $this->assertStatus($change->status, ['internal_review']);
        $impact = $this->assertImpactExists($change);

        if ($impact->requires_customer_approval) {
            throw new DomainException(trans_message('change_management.errors.customer_approval_required'));
        }

        return $this->approve($change, $userId, 'internal', $comment);
    }

    public function customerApprove(ChangeRequest $change, int $userId, ?string $comment = null): ChangeRequest
    {
        $this->assertStatus($change->status, ['customer_review']);
        $impact = $this->assertImpactExists($change);

        if (!$impact->requires_customer_approval) {
            throw new DomainException(trans_message('change_management.errors.customer_approval_not_required'));
        }

        return $this->approve($change, $userId, 'customer', $comment);
    }

    public function createVariationOrder(ChangeRequest $change, array $data): VariationOrder
    {
        if (!in_array($change->status, ['approved', 'implemented', 'closed'], true)) {
            throw new DomainException(trans_message('change_management.errors.variation_requires_approved_change'));
        }

        return VariationOrder::create([
            'organization_id' => $change->organization_id,
            'change_request_id' => $change->id,
            'variation_number' => $data['variation_number'] ?? $this->nextNumber(VariationOrder::class, (int) $change->organization_id, 'VO', 'variation_number'),
            'amount' => $data['amount'] ?? $change->impact?->cost_delta ?? 0,
            'schedule_delta_days' => $data['schedule_delta_days'] ?? $change->impact?->schedule_delta_days ?? 0,
            'description' => $data['description'] ?? null,
        ]);
    }

    public function implementChange(ChangeRequest $change, ?string $comment = null): ChangeRequest
    {
        $this->assertStatus($change->status, ['approved']);

        $change->forceFill([
            'status' => 'implemented',
            'implementation_comment' => $comment,
            'implemented_at' => now(),
        ])->save();

        return $this->reloadChange($change);
    }

    public function closeChange(ChangeRequest $change): ChangeRequest
    {
        if ($change->status !== 'implemented') {
            throw new DomainException(trans_message('change_management.errors.implementation_required'));
        }

        $change->forceFill([
            'status' => 'closed',
            'closed_at' => now(),
        ])->save();

        return $this->reloadChange($change);
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

        return ChangeClaim::create([
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
        ])->load(['changeRequest']);
    }

    private function approve(ChangeRequest $change, int $userId, string $approvalType, ?string $comment): ChangeRequest
    {
        return DB::transaction(function () use ($change, $userId, $approvalType, $comment): ChangeRequest {
            ChangeApproval::create([
                'organization_id' => $change->organization_id,
                'change_request_id' => $change->id,
                'approved_by_user_id' => $userId,
                'approval_type' => $approvalType,
                'status' => 'approved',
                'comment' => $comment,
                'decided_at' => now(),
            ]);

            $change->forceFill([
                'status' => 'approved',
                'approved_at' => now(),
            ])->save();

            return $this->reloadChange($change);
        });
    }

    private function assertProjectInOrganization(int $projectId, int $organizationId): void
    {
        $exists = Project::query()
            ->whereKey($projectId)
            ->where('organization_id', $organizationId)
            ->exists();

        if (!$exists) {
            throw new DomainException(trans_message('change_management.errors.project_scope'));
        }
    }

    private function assertStatus(string $currentStatus, array $allowedStatuses): void
    {
        if (!in_array($currentStatus, $allowedStatuses, true)) {
            throw new DomainException(trans_message('change_management.errors.invalid_status'));
        }
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
