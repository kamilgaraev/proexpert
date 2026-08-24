<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Versioning;

use App\BusinessModules\Features\BudgetEstimates\Services\EstimateVersioningService;
use App\Models\Estimate;
use App\Models\EstimateVersion;
use DomainException;
use Illuminate\Support\Facades\DB;

final class EstimateRevisionService
{
    public function __construct(
        private readonly EstimateVersionRestoreService $restoreService,
        private readonly EstimateVersioningService $versioningService,
    ) {}

    public function start(
        Estimate $estimate,
        int $actorId,
        string $reason,
        ?string $idempotencyKey = null
    ): Estimate {
        return DB::transaction(function () use ($estimate, $actorId, $reason, $idempotencyKey): Estimate {
            $lockedEstimate = Estimate::query()->whereKey($estimate->id)->lockForUpdate()->firstOrFail();

            if ($idempotencyKey !== null) {
                $existingRevision = EstimateVersion::query()
                    ->where('estimate_id', $lockedEstimate->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->where('snapshot_type', 'revision_start')
                    ->first();

                if ($existingRevision !== null) {
                    return $lockedEstimate->fresh();
                }
            }

            if ($lockedEstimate->status !== 'approved' || $lockedEstimate->current_version_id === null) {
                throw new DomainException(trans_message('estimate.revision_requires_approved_estimate'));
            }

            $currentVersion = EstimateVersion::query()
                ->whereKey($lockedEstimate->current_version_id)
                ->where('estimate_id', $lockedEstimate->id)
                ->where('status', 'approved')
                ->lockForUpdate()
                ->firstOrFail();

            $lockedEstimate->forceFill([
                'status' => 'draft',
                'approved_by_user_id' => null,
                'approved_at' => null,
            ])->save();

            $workingCopy = $this->restoreService->restoreWorkingCopy($lockedEstimate, $currentVersion);

            $this->versioningService->createSnapshot(
                estimate: $workingCopy,
                actorId: $actorId,
                label: 'Новая редакция версии '.$currentVersion->version_number,
                comment: $reason,
                snapshotType: 'revision_start',
                idempotencyKey: $idempotencyKey
            );

            return $workingCopy->fresh();
        });
    }
}
