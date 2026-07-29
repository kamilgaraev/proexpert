<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\Queries;

use App\BusinessModules\Core\MultiOrganization\Reporting\DTO\ValidatedHoldingDrillDownCell;
use App\BusinessModules\Core\MultiOrganization\Reporting\Models\IntercompanyContractFlowRow;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\IntercompanyContractFlowSnapshotMaterializer;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportRowQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownRequest;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportFreshnessStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use Illuminate\Database\Eloquent\Builder;

final readonly class IntercompanyContractFlowRowQuery implements ReportRowQuery, ReportDrillDownProvider
{
    private const SORTS = [
        'period_start',
        'project_id',
        'counterparty_organization_id',
        'currency',
        'internal_minor',
        'external_minor',
        'unclassified_minor',
        'total_minor',
        'internal_share',
        'external_share',
        'unclassified_share',
        'linked_spread_minor',
    ];

    public function __construct(private IntercompanyContractFlowSnapshotMaterializer $materializer)
    {
    }

    public function page(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportWindowSort $sort,
        ?ReportCursor $cursor,
        int $limit,
    ): ReportPage {
        $this->assertRequest($context, $snapshot, $sort);
        if ($cursor !== null) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_CURSOR_INVALID, ['fields' => ['cursor']]);
        }

        $snapshotRecord = $this->materializer->snapshot($context, $snapshot);
        $records = $this->ordered($context, $snapshot, $sort)->limit($limit + 1)->get();
        $hasMore = $records->count() > $limit;
        $rows = $records->take($limit)->map($this->row(...))->values()->all();

        return new ReportPage(
            $rows,
            $snapshotRecord->totals,
            ReportFreshnessStatus::from((string) $snapshotRecord->freshness_status),
            $this->materializer->quality($snapshotRecord),
            null,
            $limit,
            $hasMore,
            $sort,
        );
    }

    public function cursor(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportWindowSort $sort,
        int $chunkSize,
    ): iterable {
        $this->assertRequest($context, $snapshot, $sort);
        $queryHash = $snapshot->watermarks['query_hash'] ?? null;
        if (!is_string($queryHash)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR);
        }

        foreach ($this->ordered($context, $snapshot, $sort)->cursor() as $record) {
            yield [
                'row_key' => (string) $record->row_key,
                'values' => $this->row($record),
                'snapshot_id' => $snapshot->id,
                'query_hash' => $queryHash,
                'source_hash' => $snapshot->sourceHash->value,
            ];
        }
    }

    public function drillDown(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportDrillDownRequest $request,
    ): ReportDrillDownResult {
        $this->assertSnapshot($context, $snapshot);

        throw ReportContractException::fromCode(ReportErrorCode::REPORT_CURSOR_INVALID, ['fields' => ['token']]);
    }

    public function drillDownValidated(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ValidatedHoldingDrillDownCell $cell,
    ): ReportDrillDownResult {
        $this->assertSnapshot($context, $snapshot);
        $row = $this->base($context, $snapshot)->where('row_key', $cell->rowKey)->first();
        if (!$row instanceof IntercompanyContractFlowRow) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_FILTER_VALUE_NOT_FOUND);
        }

        $details = [];
        foreach ($row->source_refs as $index => $sourceRef) {
            $details[] = [
                'row_key' => $cell->rowKey . ':source:' . $index,
                'column_id' => $cell->columnId,
                'source_ref' => $sourceRef,
            ];
        }

        return new ReportDrillDownResult($details, null, []);
    }

    private function ordered(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportWindowSort $sort,
    ): Builder {
        $direction = $sort->direction === ReportSortDirection::ASC ? 'asc' : 'desc';

        return $this->base($context, $snapshot)
            ->orderBy($sort->field, $direction)
            ->orderBy('row_key', $direction);
    }

    private function base(ReportExecutionContext $context, ReportSnapshotRef $snapshot): Builder
    {
        return IntercompanyContractFlowRow::query()
            ->where('organization_id', $context->scope->organizationId)
            ->where('snapshot_id', $snapshot->id);
    }

    private function assertRequest(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportWindowSort $sort,
    ): void {
        $this->assertSnapshot($context, $snapshot);
        if (!in_array($sort->field, self::SORTS, true)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SORT_UNSUPPORTED, ['fields' => ['sort']]);
        }
    }

    private function assertSnapshot(ReportExecutionContext $context, ReportSnapshotRef $snapshot): void
    {
        if ($snapshot->kind !== IntercompanyContractFlowSnapshotMaterializer::CODE
            || $snapshot->scope->canonicalIdentity() !== $context->scope->canonicalIdentity()) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN);
        }
    }

    private function row(IntercompanyContractFlowRow $record): array
    {
        return [
            'row_key' => (string) $record->row_key,
            'project_id' => (int) $record->project_id,
            'allocation_id' => (int) $record->allocation_id,
            'counterparty_organization_id' => $record->counterparty_organization_id === null ? null : (int) $record->counterparty_organization_id,
            'currency' => $record->currency,
            'period_start' => $record->period_start->format('Y-m-d'),
            'internal_minor' => (int) $record->internal_minor,
            'external_minor' => (int) $record->external_minor,
            'unclassified_minor' => (int) $record->unclassified_minor,
            'total_minor' => (int) $record->total_minor,
            'internal_share' => $record->internal_share === null ? null : (string) $record->internal_share,
            'external_share' => $record->external_share === null ? null : (string) $record->external_share,
            'unclassified_share' => $record->unclassified_share === null ? null : (string) $record->unclassified_share,
            'linked_spread_minor' => $record->linked_spread_minor === null ? null : (int) $record->linked_spread_minor,
            'source_refs' => $record->source_refs,
        ];
    }
}
