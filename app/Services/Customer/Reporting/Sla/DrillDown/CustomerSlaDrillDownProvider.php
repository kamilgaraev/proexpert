<?php

declare(strict_types=1);

namespace App\Services\Customer\Reporting\Sla\DrillDown;

use App\BusinessModules\Core\Reporting\Application\Access\ReportEvidenceRedactor;
use App\BusinessModules\Core\Reporting\Application\Access\ReportSourceObjectAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Rows\StableDrillDownPage;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownTokenColumns;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownInput;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\Services\Customer\Reporting\Sla\Models\CustomerSlaRow;
use InvalidArgumentException;

final readonly class CustomerSlaDrillDownProvider implements ReportDrillDownProvider, ReportDrillDownTokenColumns
{
    public function drillDownTokenColumns(): array
    {
        return ['drill' => 'event_refs'];
    }

    public function __construct(
        private ReportSourceObjectAuthorizer $sources,
        private ReportEvidenceRedactor $redactor,
    ) {}

    public function drillDown(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportDrillDownInput $input,
    ): ReportDrillDownResult {
        if (
            $snapshot->kind !== 'customer_sla'
            || $context->scope->organizationId !== $snapshot->scope->organizationId
        ) {
            throw new InvalidArgumentException('customer_sla_drill_down_invalid');
        }
        $rowKey = $input->cell->rowKey;
        $row = CustomerSlaRow::query()
            ->where('organization_id', $context->scope->organizationId)
            ->where('snapshot_id', $snapshot->id)
            ->where('row_key', $rowKey)
            ->firstOrFail();
        $sourceType = 'customer_'.(string) $row->workflow_type;
        $availability = $this->sources->availability(
            $context,
            $sourceType,
            (int) $row->workflow_id,
            (int) $row->organization_id,
            $row->project_id === null ? null : (int) $row->project_id,
        );
        $events = array_map(
            fn (array $ref): array => $this->redactor->event($ref, $availability),
            $row->event_refs,
        );
        $page = StableDrillDownPage::fromRows(
            $events,
            $input->cursor,
            $input->limit,
        );

        return new ReportDrillDownResult($page->rows, $page->nextCursor, []);
    }

}
