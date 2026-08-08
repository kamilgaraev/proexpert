<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\DrillDown;

use App\BusinessModules\Core\Reporting\Application\Access\ReportSourceObjectAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Rows\StableDrillDownPage;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownTokenColumns;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownInput;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\Models\HandoverReadinessRow;
use InvalidArgumentException;

final readonly class HandoverReadinessDrillDownProvider implements ReportDrillDownProvider, ReportDrillDownTokenColumns
{
    public function __construct(private ReportSourceObjectAuthorizer $sources) {}

    public function drillDownTokenColumns(): array
    {
        return ['drill' => 'evidence_refs'];
    }

    public function drillDown(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportDrillDownInput $input,
    ): ReportDrillDownResult {
        if (
            $snapshot->kind !== 'handover_readiness'
            || $context->scope->organizationId !== $snapshot->scope->organizationId
        ) {
            throw new InvalidArgumentException('handover_readiness_drill_down_invalid');
        }
        $rowKey = $input->cell->rowKey;
        $row = HandoverReadinessRow::query()
            ->where('organization_id', $context->scope->organizationId)
            ->where('snapshot_id', $snapshot->id)
            ->where('row_key', $rowKey)
            ->firstOrFail();
        $evidence = [];
        foreach ($row->evidence_refs as $ref) {
            $sourceType = (string) $ref['source_type'];
            $sourceId = (int) $ref['source_id'];
            $availability = $this->sources->availability(
                $context,
                $sourceType,
                $sourceId,
                (int) $row->organization_id,
                (int) $row->project_id,
            );
            $evidence[] = $availability === 'available'
                ? [
                    'row_key' => (string) $ref['event_id'],
                    'event_id' => (string) $ref['event_id'],
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'availability' => $availability,
                ]
                : [
                    'row_key' => 'redacted:'.hash('sha256', (string) $ref['event_id']),
                    'source_type' => $sourceType,
                    'availability' => 'redacted',
                ];
        }
        $page = StableDrillDownPage::fromRows(
            $evidence,
            $input->cursor,
            $input->limit,
        );

        return new ReportDrillDownResult($page->rows, $page->nextCursor, []);
    }

}
