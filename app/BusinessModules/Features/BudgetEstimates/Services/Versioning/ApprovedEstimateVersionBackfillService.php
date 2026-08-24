<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Versioning;

use App\Models\Estimate;
use App\Models\EstimateVersion;
use Illuminate\Support\Facades\DB;

final class ApprovedEstimateVersionBackfillService
{
    public function __construct(
        private readonly EstimateSnapshotBuilder $snapshotBuilder,
    ) {}

    public function backfill(): int
    {
        $backfilled = 0;

        Estimate::query()
            ->where('status', 'approved')
            ->whereNull('current_version_id')
            ->orderBy('id')
            ->chunkById(100, function ($estimates) use (&$backfilled): void {
                foreach ($estimates as $estimate) {
                    $created = DB::transaction(function () use ($estimate): bool {
                        $lockedEstimate = Estimate::query()
                            ->whereKey($estimate->id)
                            ->lockForUpdate()
                            ->firstOrFail();
                        if ($lockedEstimate->status !== 'approved' || $lockedEstimate->current_version_id !== null) {
                            return false;
                        }

                        $snapshot = $this->snapshotBuilder->build($lockedEstimate);
                        $versionNumber = ((int) EstimateVersion::query()
                            ->where('estimate_id', $lockedEstimate->id)
                            ->orderByDesc('version_number')
                            ->lockForUpdate()
                            ->value('version_number')) + 1;
                        $approvedAt = $lockedEstimate->approved_at ?? $lockedEstimate->updated_at ?? now();
                        $version = EstimateVersion::query()->create([
                            'estimate_id' => $lockedEstimate->id,
                            'organization_id' => $lockedEstimate->organization_id,
                            'created_by_user_id' => null,
                            'approved_by_user_id' => $lockedEstimate->approved_by_user_id,
                            'approved_at' => $approvedAt,
                            'version_number' => $versionNumber,
                            'label' => 'Миграционная утверждённая версия',
                            'comment' => null,
                            'snapshot_type' => 'approval',
                            'estimate_status' => 'approved',
                            'snapshot' => $snapshot,
                            'snapshot_hash' => $this->snapshotBuilder->hash($snapshot),
                            'total_amount' => $lockedEstimate->total_amount ?? 0,
                            'total_amount_with_vat' => $lockedEstimate->total_amount_with_vat ?? $lockedEstimate->total_amount ?? 0,
                            'total_direct_costs' => $lockedEstimate->total_direct_costs ?? 0,
                            'status' => 'approved',
                        ]);

                        DB::table('estimates')
                            ->where('id', $lockedEstimate->id)
                            ->whereNull('current_version_id')
                            ->update([
                                'current_version_id' => $version->id,
                                'updated_at' => $lockedEstimate->updated_at,
                            ]);

                        return true;
                    });

                    if ($created) {
                        $backfilled++;
                    }
                }
            });

        return $backfilled;
    }
}
