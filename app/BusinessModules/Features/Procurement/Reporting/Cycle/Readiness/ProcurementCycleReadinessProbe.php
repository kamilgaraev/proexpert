<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\Readiness;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionReadinessProbe;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Queries\ProcurementCycleFilteredUniverse;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Models\ProcurementProcessEvent;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequestLine;
use App\Support\Reporting\ReportSourceAccessPolicy;
use App\Support\Reporting\SourceReadinessResult;
use DateTimeImmutable;

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
        $this->inspect($context, $query)->assertReady('procurement_cycle');
    }

    public function inspect(ReportExecutionContext $context, ReportQuery $query): SourceReadinessResult
    {
        $projects = $context->scope->projectIds;
        $allowedLineIds = $this->sourceAccess->allowedIds(
            $context->scope->resources,
            'purchase_request_line',
        );
        $eligibleLineIds = $this->universe->query($context, $query);
        $eligible = (clone $eligibleLineIds)->count('purchase_request_lines.id');
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
        foreach ([
            'request_approved' => PurchaseRequestLine::query()
                ->join('purchase_requests as readiness_request', 'readiness_request.id', '=', 'purchase_request_lines.purchase_request_id')
                ->whereIn('purchase_request_lines.id', clone $eligibleLineIds)
                ->where('readiness_request.status', 'approved')
                ->count('purchase_request_lines.id'),
            'cancelled' => PurchaseRequestLine::query()
                ->join('purchase_requests as readiness_request', 'readiness_request.id', '=', 'purchase_request_lines.purchase_request_id')
                ->whereIn('purchase_request_lines.id', clone $eligibleLineIds)
                ->whereIn('readiness_request.status', ['rejected', 'cancelled'])
                ->count('purchase_request_lines.id'),
            'solicitation_sent' => PurchaseRequestLine::query()
                ->join('supplier_request_lines as readiness_supplier_line', 'readiness_supplier_line.purchase_request_line_id', '=', 'purchase_request_lines.id')
                ->join('supplier_requests as readiness_supplier_request', 'readiness_supplier_request.id', '=', 'readiness_supplier_line.supplier_request_id')
                ->whereIn('purchase_request_lines.id', clone $eligibleLineIds)
                ->whereNotNull('readiness_supplier_request.sent_at')
                ->distinct()
                ->count('purchase_request_lines.id'),
            'supplier_responded' => PurchaseRequestLine::query()
                ->join('supplier_request_lines as readiness_supplier_line', 'readiness_supplier_line.purchase_request_line_id', '=', 'purchase_request_lines.id')
                ->join('supplier_proposal_lines as readiness_proposal_line', 'readiness_proposal_line.supplier_request_line_id', '=', 'readiness_supplier_line.id')
                ->whereIn('purchase_request_lines.id', clone $eligibleLineIds)
                ->distinct()
                ->count('purchase_request_lines.id'),
        ] as $eventCode => $expectedCount) {
            $actualCount = (clone $events)
                ->where('event_code', $eventCode)
                ->distinct()
                ->count('purchase_request_line_id');
            $stageGaps += max(0, $expectedCount - $actualCount);
        }

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
