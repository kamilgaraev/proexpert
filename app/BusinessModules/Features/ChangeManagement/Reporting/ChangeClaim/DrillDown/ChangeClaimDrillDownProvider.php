<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\DrillDown;

use App\BusinessModules\Core\Payments\Reporting\FinanceSourceAccessPolicy;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownInput;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResourceLink;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Queries\ChangeClaimRowQuery;
use DomainException;

final readonly class ChangeClaimDrillDownProvider implements ReportDrillDownProvider
{
    public function __construct(
        private ChangeClaimRowQuery $rows,
        private FinanceSourceAccessPolicy $sourceAccess,
    )
    {
    }

    public function drillDown(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportDrillDownInput $input,
    ): ReportDrillDownResult {
        $record = $this->rows->row($context, $snapshot, $input->cell->rowKey);
        $rows = [];
        $links = [];
        foreach ($this->sourceAccess->visibleRefs(
            $context,
            $record->source_refs,
            ['change_request', 'change_claim', 'contract_allocation', 'budget_line', 'schedule_task'],
        ) as $ref) {
            $type = $ref['type'];
            $id = (string) $ref['id'];
            $rows[] = ['row_key' => $type.':'.$id, 'source_type' => $type, 'source_id' => $id];
            $links[] = new ReportResourceLink($type, 'r'.$id, $this->routeName($type), ['id' => (int) $id], 'available');
        }

        return new ReportDrillDownResult($rows, null, $links);
    }

    private function routeName(string $type): string
    {
        return match ($type) {
            'change_request' => 'admin.change-management.changes.show',
            'change_claim' => 'admin.change-management.claims.show',
            'contract_allocation' => 'admin.contracts.show',
            'budget_line' => 'admin.budgeting.lines.show',
            'schedule_task' => 'admin.schedules.tasks.show',
            default => throw new DomainException('report_drill_down_source_invalid'),
        };
    }
}
