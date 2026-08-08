<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\Readiness;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionReadinessProbe;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Models\ProcurementProcessEvent;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Queries\ProcurementCycleFilteredUniverse;
use App\Support\Reporting\ReportSourceAccessPolicy;
use App\Support\Reporting\SourceReadinessResult;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final readonly class ProcurementCycleReadinessProbe implements ReportDefinitionReadinessProbe
{
    public function __construct(
        private ReportSourceAccessPolicy $sourceAccess,
        private ProcurementCycleFilteredUniverse $universe,
    ) {}

    public function supports(ReportDefinition $definition): bool
    {
        return $definition->code === 'procurement_cycle'
            && $definition->formulaVersion === 'procurement-cycle.v1';
    }

    public function assertReady(ReportExecutionContext $context, ReportQuery $query): void
    {
        $this->inspect($context, $query)->assertReady();
    }

    public function inspect(ReportExecutionContext $context, ReportQuery $query): SourceReadinessResult
    {
        $projects = $context->scope->projectIds;
        $allowedLineIds = $this->sourceAccess->allowedIds(
            $context->scope->resources,
            'purchase_request_line',
        );
        $eligibleLineIds = $this->universe->query($context, $query);
        $eligible = DB::query()
            ->fromSub(clone $eligibleLineIds, 'eligible_cycle_lines')
            ->count();
        if ($eligible === 0) {
            return SourceReadinessResult::empty();
        }
        $events = ProcurementProcessEvent::query()
            ->where('organization_id', $context->scope->organizationId)
            ->whereIn('purchase_request_line_id', clone $eligibleLineIds)
            ->when(
                $allowedLineIds !== null,
                static fn ($builder) => $builder->whereIn('purchase_request_line_id', $allowedLineIds),
            )
            ->when($projects !== [], static fn ($builder) => $builder->whereIn('project_id', $projects))
            ->where('occurred_at', '<=', $query->asOf);
        $projected = (clone $events)
            ->where('event_code', 'request_created')
            ->distinct()
            ->count('purchase_request_line_id');
        $stageGaps = 0;

        return new SourceReadinessResult(
            $eligible,
            $projected,
            max(0, $eligible - $projected) + $stageGaps,
            0,
            (clone $events)->where('event_version', '<', 1)->count(),
            (clone $events)->whereRaw('LENGTH(source_hash) <> 64')->count(),
            new DateTimeImmutable,
        );
    }
}
