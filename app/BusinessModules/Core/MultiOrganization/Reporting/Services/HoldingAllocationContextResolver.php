<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\Services;

use App\BusinessModules\Core\MultiOrganization\Reporting\DTO\HoldingAllocationContextSnapshot;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class HoldingAllocationContextResolver
{
    public function __construct(
        private HoldingReportingSourceCoverage $coverage = new HoldingReportingSourceCoverage,
    ) {}

    public function resolve(
        int $organizationId,
        int $contractId,
        int $projectId,
        DateTimeInterface $asOf,
        ?int $allocationId = null,
        bool $requireActive = true,
    ): HoldingAllocationContextSnapshot {
        if (min($organizationId, $contractId, $projectId) < 1
            || ($allocationId !== null && $allocationId < 1)) {
            throw new InvalidArgumentException('holding_allocation_context_identity_invalid');
        }

        $coverage = $this->coverage->assertCovers(
            HoldingReportingSourceCoverage::ALLOCATION_DIMENSIONS,
            $asOf,
        );
        $timeline = DB::table('holding_allocation_context_events')
            ->select('*')
            ->selectRaw(
                'ROW_NUMBER() OVER (PARTITION BY allocation_id ORDER BY observed_at DESC, id DESC) AS timeline_position',
            )
            ->where('contract_id', $contractId)
            ->where('project_id', $projectId)
            ->where('observed_at', '<=', $asOf);
        if ($allocationId !== null) {
            $timeline->where('allocation_id', $allocationId);
        }
        $latest = DB::query()
            ->fromSub($timeline, 'latest_holding_allocation_context')
            ->where('timeline_position', 1);
        if ($requireActive) {
            $latest
                ->where('is_deleted', false)
                ->where('is_active', true);
        }
        $event = $latest
            ->orderByDesc('allocation_id')
            ->first();
        if (! is_object($event)
            || (int) $event->organization_id !== $organizationId
            || ! (bool) $event->is_resolvable
            || $event->allocated_percentage === null
            || ($requireActive && ((bool) $event->is_deleted || ! (bool) $event->is_active))) {
            throw new InvalidArgumentException('holding_allocation_context_unavailable');
        }

        return new HoldingAllocationContextSnapshot(
            (int) $event->id,
            (int) $event->allocation_id,
            (int) $event->contract_id,
            (int) $event->organization_id,
            (int) $event->project_id,
            (string) $event->allocation_type,
            (string) $event->allocated_percentage,
            (string) $event->evidence_hash,
            (string) $coverage['coverage_started_at'],
        );
    }
}
