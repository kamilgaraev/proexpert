<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\Readiness;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionReadinessProbe;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequestLine;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Models\ProcurementProcessEvent;
use App\Support\Reporting\SourceReadinessResult;
use DateTimeImmutable;

final readonly class ProcurementCycleReadinessProbe implements ReportDefinitionReadinessProbe
{
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
        $eligible = PurchaseRequestLine::query()
            ->join('purchase_requests', 'purchase_requests.id', '=', 'purchase_request_lines.purchase_request_id')
            ->where('purchase_requests.organization_id', $context->scope->organizationId)
            ->where('purchase_requests.created_at', '<=', $query->asOf)
            ->count('purchase_request_lines.id');
        $events = ProcurementProcessEvent::query()
            ->where('organization_id', $context->scope->organizationId)
            ->where('occurred_at', '<=', $query->asOf);
        $projected = (clone $events)
            ->where('event_code', 'request_created')
            ->distinct()
            ->count('purchase_request_line_id');
        $stageGaps = 0;
        foreach ([
            'request_approved' => PurchaseRequestLine::query()
                ->join('purchase_requests', 'purchase_requests.id', '=', 'purchase_request_lines.purchase_request_id')
                ->where('purchase_requests.organization_id', $context->scope->organizationId)
                ->where('purchase_requests.status', 'approved')
                ->count('purchase_request_lines.id'),
            'cancelled' => PurchaseRequestLine::query()
                ->join('purchase_requests', 'purchase_requests.id', '=', 'purchase_request_lines.purchase_request_id')
                ->where('purchase_requests.organization_id', $context->scope->organizationId)
                ->whereIn('purchase_requests.status', ['rejected', 'cancelled'])
                ->count('purchase_request_lines.id'),
            'solicitation_sent' => PurchaseRequestLine::query()
                ->join('supplier_request_lines', 'supplier_request_lines.purchase_request_line_id', '=', 'purchase_request_lines.id')
                ->join('supplier_requests', 'supplier_requests.id', '=', 'supplier_request_lines.supplier_request_id')
                ->where('supplier_requests.organization_id', $context->scope->organizationId)
                ->whereNotNull('supplier_requests.sent_at')
                ->distinct()
                ->count('purchase_request_lines.id'),
            'supplier_responded' => PurchaseRequestLine::query()
                ->join('supplier_request_lines', 'supplier_request_lines.purchase_request_line_id', '=', 'purchase_request_lines.id')
                ->join('supplier_proposal_lines', 'supplier_proposal_lines.supplier_request_line_id', '=', 'supplier_request_lines.id')
                ->join('supplier_proposals', 'supplier_proposals.id', '=', 'supplier_proposal_lines.supplier_proposal_id')
                ->where('supplier_proposals.organization_id', $context->scope->organizationId)
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
