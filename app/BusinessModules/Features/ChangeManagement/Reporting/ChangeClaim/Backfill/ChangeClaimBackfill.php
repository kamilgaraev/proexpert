<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Backfill;

use App\BusinessModules\Features\ChangeManagement\Models\ChangeRequest;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Models\ChangeRequestVersion;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Services\ChangeWorkflowEventRecorder;
use Carbon\CarbonImmutable;
use DomainException;

final readonly class ChangeClaimBackfill
{
    public function __construct(private ChangeWorkflowEventRecorder $recorder)
    {
    }

    public function slice(int $organizationId, int $afterId, int $limit): int
    {
        if ($organizationId < 1 || $afterId < 0 || $limit < 1 || $limit > 1000) {
            throw new DomainException('change_claim_backfill_slice_invalid');
        }
        $changes = ChangeRequest::query()
            ->with('impact')
            ->where('organization_id', $organizationId)
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($limit)
            ->get();
        $lastId = $afterId;
        foreach ($changes as $change) {
            $lastId = (int) $change->id;
            if (ChangeRequestVersion::query()
                ->where('organization_id', $organizationId)
                ->where('change_request_id', $change->id)
                ->exists()) {
                continue;
            }
            $links = is_array($change->linked_entities) ? $change->linked_entities : [];
            if (!is_string($links['currency'] ?? null)
                || !isset($links['contract_project_allocation_id'])
                || $change->impact === null) {
                continue;
            }
            $timestamp = $change->approved_at
                ?? $change->submitted_at
                ?? $change->created_at;
            if ($timestamp === null) {
                continue;
            }
            $eventType = $change->approved_at !== null
                ? 'approve'
                : ($change->submitted_at !== null ? 'submit' : 'create');
            $this->recorder->record(
                $change,
                $eventType,
                CarbonImmutable::instance($timestamp),
                $change->created_by_user_id === null ? null : (int) $change->created_by_user_id,
            );
        }

        return $lastId;
    }
}
