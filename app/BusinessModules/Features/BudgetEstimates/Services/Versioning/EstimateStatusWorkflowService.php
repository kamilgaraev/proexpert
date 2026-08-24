<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Versioning;

use App\Models\Estimate;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class EstimateStatusWorkflowService
{
    private const TRANSITIONS = [
        'draft' => ['in_review', 'cancelled'],
        'in_review' => ['draft', 'approved', 'rejected', 'cancelled'],
        'rejected' => ['draft', 'cancelled'],
        'approved' => [],
        'cancelled' => [],
    ];

    public function transition(
        Estimate $estimate,
        string $newStatus,
        int $actorId,
        ?string $comment,
        string $source
    ): Estimate {
        return DB::transaction(function () use ($estimate, $newStatus, $actorId, $comment, $source): Estimate {
            $lockedEstimate = Estimate::query()->whereKey($estimate->id)->lockForUpdate()->firstOrFail();
            $oldStatus = (string) $lockedEstimate->status;

            if ($oldStatus === $newStatus && $newStatus === 'approved') {
                return $lockedEstimate->fresh(['project', 'approvedBy', 'currentVersion']);
            }

            if (! in_array($newStatus, self::TRANSITIONS[$oldStatus] ?? [], true)) {
                throw new DomainException(trans_message('estimate.status_invalid_transition', [
                    'from' => $oldStatus,
                    'to' => $newStatus,
                ]));
            }

            $metadata = is_array($lockedEstimate->metadata) ? $lockedEstimate->metadata : [];
            $history = is_array($metadata['approval_history'] ?? null) ? $metadata['approval_history'] : [];
            $history[] = [
                'from' => $oldStatus,
                'to' => $newStatus,
                'source' => $source,
                'user_id' => $actorId,
                'comment' => $comment,
                'created_at' => now()->toIso8601String(),
            ];
            $metadata['approval_history'] = array_values($history);

            $lockedEstimate->forceFill([
                'status' => $newStatus,
                'approved_by_user_id' => $newStatus === 'approved' ? $actorId : null,
                'approved_at' => $newStatus === 'approved' ? now() : null,
                'metadata' => $metadata,
            ])->save();

            Log::info('estimate.status_updated', [
                'estimate_id' => $lockedEstimate->id,
                'organization_id' => $lockedEstimate->organization_id,
                'project_id' => $lockedEstimate->project_id,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'user_id' => $actorId,
                'source' => $source,
            ]);

            return $lockedEstimate->fresh([
                'project',
                'approvedBy',
                'currentVersion',
            ]);
        });
    }
}
