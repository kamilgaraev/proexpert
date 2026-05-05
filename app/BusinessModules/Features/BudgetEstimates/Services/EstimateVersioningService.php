<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services;

use App\BusinessModules\Features\BudgetEstimates\Services\Versioning\EstimateSnapshotBuilder;
use App\Models\Estimate;
use App\Models\EstimateVersion;
use Illuminate\Support\Facades\DB;

class EstimateVersioningService
{
    public function __construct(
        private readonly EstimateSnapshotBuilder $snapshotBuilder,
    ) {
    }

    public function createSnapshot(
        Estimate $estimate,
        int $actorId,
        ?string $label = null,
        ?string $comment = null,
        string $snapshotType = 'manual'
    ): EstimateVersion {
        return DB::transaction(function () use ($estimate, $actorId, $label, $comment, $snapshotType): EstimateVersion {
            $lockedEstimate = Estimate::query()
                ->whereKey($estimate->id)
                ->lockForUpdate()
                ->firstOrFail();

            $snapshot = $this->snapshotBuilder->build($lockedEstimate);
            $snapshotHash = $this->snapshotBuilder->hash($snapshot);

            if ($snapshotType === 'approval') {
                $existingVersion = EstimateVersion::query()
                    ->where('estimate_id', $lockedEstimate->id)
                    ->where('snapshot_type', 'approval')
                    ->where('snapshot_hash', $snapshotHash)
                    ->first();

                if ($existingVersion !== null) {
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

            return EstimateVersion::query()->create([
                'estimate_id' => $lockedEstimate->id,
                'organization_id' => $lockedEstimate->organization_id,
                'created_by_user_id' => $actorId,
                'approved_by_user_id' => $approvedByUserId,
                'approved_at' => $approvedAt,
                'version_number' => $versionNumber,
                'label' => $label ?? 'Версия ' . $versionNumber,
                'comment' => $comment,
                'snapshot_type' => $snapshotType,
                'estimate_status' => $lockedEstimate->status,
                'snapshot' => $snapshot,
                'snapshot_hash' => $snapshotHash,
                'total_amount' => $lockedEstimate->total_amount ?? 0,
                'total_amount_with_vat' => $lockedEstimate->total_amount_with_vat ?? 0,
                'total_direct_costs' => $lockedEstimate->total_direct_costs ?? 0,
            ]);
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

    public function findVersionForEstimate(Estimate $estimate, int $versionId): EstimateVersion
    {
        return EstimateVersion::query()
            ->where('estimate_id', $estimate->id)
            ->with(['createdBy:id,name', 'approvedBy:id,name'])
            ->findOrFail($versionId);
    }

    public function resourcePayload(EstimateVersion $version): array
    {
        $version->loadMissing(['createdBy:id,name', 'approvedBy:id,name']);

        return [
            'id' => $version->id,
            'estimateId' => $version->estimate_id,
            'organizationId' => $version->organization_id,
            'versionNumber' => $version->version_number,
            'label' => $version->label,
            'comment' => $version->comment,
            'snapshotType' => $version->snapshot_type,
            'estimateStatus' => $version->estimate_status,
            'snapshotHash' => $version->snapshot_hash,
            'snapshot' => $version->snapshot,
            'totals' => [
                'totalAmount' => $version->total_amount,
                'totalAmountWithVat' => $version->total_amount_with_vat,
                'totalDirectCosts' => $version->total_direct_costs,
            ],
            'createdBy' => $version->createdBy ? [
                'id' => $version->createdBy->id,
                'name' => $version->createdBy->name,
            ] : null,
            'approvedBy' => $version->approvedBy ? [
                'id' => $version->approvedBy->id,
                'name' => $version->approvedBy->name,
            ] : null,
            'approvedAt' => $version->approved_at?->toISOString(),
            'createdAt' => $version->created_at?->toISOString(),
        ];
    }
}
