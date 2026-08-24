<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services;

use App\BusinessModules\Features\BudgetEstimates\Services\Versioning\EstimateSnapshotBuilder;
use App\Models\Estimate;
use App\Models\EstimateVersion;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class EstimateVersioningService
{
    public function __construct(
        private readonly EstimateSnapshotBuilder $snapshotBuilder,
    ) {}

    public function createSnapshot(
        Estimate $estimate,
        int $actorId,
        ?string $label = null,
        ?string $comment = null,
        string $snapshotType = 'manual',
        ?string $idempotencyKey = null
    ): EstimateVersion {
        return DB::transaction(function () use (
            $estimate,
            $actorId,
            $label,
            $comment,
            $snapshotType,
            $idempotencyKey
        ): EstimateVersion {
            $lockedEstimate = Estimate::query()
                ->whereKey($estimate->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($idempotencyKey !== null) {
                $idempotentVersion = EstimateVersion::query()
                    ->where('estimate_id', $lockedEstimate->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($idempotentVersion !== null) {
                    return $idempotentVersion;
                }
            }

            $snapshot = $this->snapshotBuilder->build($lockedEstimate);
            $snapshotHash = $this->snapshotBuilder->hash($snapshot);

            if ($snapshotType === 'approval') {
                $existingVersion = EstimateVersion::query()
                    ->where('estimate_id', $lockedEstimate->id)
                    ->where('snapshot_type', 'approval')
                    ->where('snapshot_hash', $snapshotHash)
                    ->where('status', 'approved')
                    ->first();

                if ($existingVersion !== null) {
                    $this->activateApprovalVersion($lockedEstimate, $existingVersion);

                    return $existingVersion;
                }
            }

            $lastVersionNumber = EstimateVersion::query()
                ->where('estimate_id', $lockedEstimate->id)
                ->orderByDesc('version_number')
                ->lockForUpdate()
                ->value('version_number');
            $versionNumber = ((int) $lastVersionNumber) + 1;

            $approvedByUserId = $snapshotType === 'approval'
                ? ($lockedEstimate->approved_by_user_id ?? $actorId)
                : $lockedEstimate->approved_by_user_id;
            $approvedAt = $snapshotType === 'approval'
                ? ($lockedEstimate->approved_at ?? now())
                : $lockedEstimate->approved_at;

            $previousCurrentVersion = $snapshotType === 'approval' && $lockedEstimate->current_version_id !== null
                ? EstimateVersion::query()->whereKey($lockedEstimate->current_version_id)->lockForUpdate()->first()
                : null;

            if ($previousCurrentVersion !== null) {
                $previousCurrentVersion->forceFill([
                    'status' => 'superseded',
                    'superseded_at' => now(),
                ])->save();
            }

            $version = EstimateVersion::query()->create([
                'estimate_id' => $lockedEstimate->id,
                'organization_id' => $lockedEstimate->organization_id,
                'created_by_user_id' => $actorId,
                'approved_by_user_id' => $approvedByUserId,
                'approved_at' => $approvedAt,
                'version_number' => $versionNumber,
                'label' => $label ?? 'Версия '.$versionNumber,
                'comment' => $comment,
                'snapshot_type' => $snapshotType,
                'estimate_status' => $lockedEstimate->status,
                'snapshot' => $snapshot,
                'snapshot_hash' => $snapshotHash,
                'total_amount' => $lockedEstimate->total_amount ?? 0,
                'total_amount_with_vat' => $lockedEstimate->total_amount_with_vat ?? 0,
                'total_direct_costs' => $lockedEstimate->total_direct_costs ?? 0,
                'status' => $snapshotType === 'approval'
                    ? 'approved'
                    : $this->snapshotStatus($lockedEstimate->status),
                'idempotency_key' => $idempotencyKey,
            ]);

            if ($snapshotType === 'approval') {
                $this->activateApprovalVersion($lockedEstimate, $version);
            }

            return $version;
        });
    }

    public function createApprovalSnapshot(Estimate $estimate, int $actorId): EstimateVersion
    {
        return $this->createSnapshot(
            estimate: $estimate,
            actorId: $actorId,
            label: 'Утвержденная версия',
            snapshotType: 'approval'
        );
    }

    public function listVersions(Estimate $estimate): array
    {
        return EstimateVersion::query()
            ->where('estimate_id', $estimate->id)
            ->with(['createdBy:id,name', 'approvedBy:id,name'])
            ->orderByDesc('version_number')
            ->get()
            ->map(fn (EstimateVersion $version): array => $this->resourcePayload($version))
            ->all();
    }

    public function paginateVersions(Estimate $estimate, int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        $perPage = max(1, min($perPage, 100));

        return EstimateVersion::query()
            ->where('estimate_id', $estimate->id)
            ->with([
                'createdBy:id,name',
                'approvedBy:id,name',
                'estimate:id,current_version_id',
            ])
            ->orderByDesc('version_number')
            ->paginate($perPage, ['*'], 'page', max(1, $page))
            ->through(fn (EstimateVersion $version): array => $this->resourcePayload($version, false));
    }

    public function findVersionForEstimate(Estimate $estimate, int $versionId): EstimateVersion
    {
        return EstimateVersion::query()
            ->where('estimate_id', $estimate->id)
            ->with(['createdBy:id,name', 'approvedBy:id,name'])
            ->findOrFail($versionId);
    }

    public function resourcePayload(EstimateVersion $version, bool $includeSnapshot = true): array
    {
        $version->loadMissing([
            'createdBy:id,name',
            'approvedBy:id,name',
            'estimate:id,current_version_id',
        ]);

        $payload = [
            'id' => $version->id,
            'estimate_id' => $version->estimate_id,
            'estimateId' => $version->estimate_id,
            'organization_id' => $version->organization_id,
            'organizationId' => $version->organization_id,
            'version_number' => $version->version_number,
            'versionNumber' => $version->version_number,
            'label' => $version->label,
            'comment' => $version->comment,
            'snapshot_type' => $version->snapshot_type,
            'snapshotType' => $version->snapshot_type,
            'estimate_status' => $version->estimate_status,
            'estimateStatus' => $version->estimate_status,
            'snapshot_hash' => $version->snapshot_hash,
            'snapshotHash' => $version->snapshot_hash,
            'status' => $version->status,
            'is_current' => (int) $version->estimate?->current_version_id === (int) $version->id,
            'isCurrent' => (int) $version->estimate?->current_version_id === (int) $version->id,
            'total_amount' => $version->total_amount,
            'total_amount_with_vat' => $version->total_amount_with_vat,
            'total_direct_costs' => $version->total_direct_costs,
            'totals' => [
                'totalAmount' => $version->total_amount,
                'totalAmountWithVat' => $version->total_amount_with_vat,
                'totalDirectCosts' => $version->total_direct_costs,
            ],
            'createdBy' => $version->createdBy ? [
                'id' => $version->createdBy->id,
                'name' => $version->createdBy->name,
            ] : null,
            'created_by' => $version->createdBy ? [
                'id' => $version->createdBy->id,
                'name' => $version->createdBy->name,
            ] : null,
            'approvedBy' => $version->approvedBy ? [
                'id' => $version->approvedBy->id,
                'name' => $version->approvedBy->name,
            ] : null,
            'approved_by' => $version->approvedBy ? [
                'id' => $version->approvedBy->id,
                'name' => $version->approvedBy->name,
            ] : null,
            'approved_at' => $version->approved_at?->toISOString(),
            'approvedAt' => $version->approved_at?->toISOString(),
            'created_at' => $version->created_at?->toISOString(),
            'createdAt' => $version->created_at?->toISOString(),
        ];

        if ($includeSnapshot) {
            $payload['snapshot'] = $version->snapshot;
        }

        return $payload;
    }

    private function activateApprovalVersion(Estimate $estimate, EstimateVersion $version): void
    {
        if ((int) $estimate->current_version_id === (int) $version->id) {
            return;
        }

        $estimate->forceFill(['current_version_id' => $version->id])->save();
    }

    private function snapshotStatus(string $estimateStatus): string
    {
        if ($estimateStatus === 'approved') {
            return 'archived';
        }

        return in_array($estimateStatus, ['draft', 'in_review', 'rejected'], true)
            ? $estimateStatus
            : 'archived';
    }
}
