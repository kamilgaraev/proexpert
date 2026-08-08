<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\DrillDown;

use App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\Models\ContractorScorecardRow;
use App\BusinessModules\Core\Reporting\Application\Access\ReportEvidenceRedactor;
use App\BusinessModules\Core\Reporting\Application\Access\ReportSourceObjectAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Rows\StableDrillDownPage;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownTokenColumns;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownInput;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use InvalidArgumentException;

final readonly class ContractorScorecardDrillDownProvider implements ReportDrillDownProvider, ReportDrillDownTokenColumns
{
    public function __construct(
        private ReportSourceObjectAuthorizer $sources,
        private ReportEvidenceRedactor $redactor,
    ) {}

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
            $snapshot->kind !== 'contractor_scorecard'
            || $context->scope->organizationId !== $snapshot->scope->organizationId
        ) {
            throw new InvalidArgumentException('contractor_scorecard_drill_down_invalid');
        }
        $rowKey = $input->cell->rowKey;
        $row = ContractorScorecardRow::query()
            ->where('organization_id', $context->scope->organizationId)
            ->where('snapshot_id', $snapshot->id)
            ->where('row_key', $rowKey)
            ->firstOrFail();
        $evidence = [];
        foreach ($row->evidence_refs as $ref) {
            $sourceType = isset($ref['review_id'])
                ? 'marketplace_review'
                : (string) ($ref['source_report_code'] ?? '');
            $sourceId = isset($ref['review_id'])
                ? (int) $ref['review_id']
                : (int) ($ref['source_row_id'] ?? 0);
            $availability = $this->sources->availability(
                $context,
                $sourceType,
                $sourceId,
                (int) $row->organization_id,
                $row->project_id === null ? null : (int) $row->project_id,
            );
            $evidence[] = $this->redactor->reference($ref, $sourceType, $sourceId, $availability);
        }
        $page = StableDrillDownPage::fromRows(
            $evidence,
            $input->cursor,
            $input->limit,
        );

        return new ReportDrillDownResult($page->rows, $page->nextCursor, []);
    }

}
