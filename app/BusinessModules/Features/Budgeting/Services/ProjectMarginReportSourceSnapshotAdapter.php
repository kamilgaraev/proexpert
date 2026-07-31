<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Services;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSourceSnapshotStore;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotHeader;
use App\BusinessModules\Features\Budgeting\DTOs\BudgetingReportSourceCloseIdentity;
use App\BusinessModules\Features\Budgeting\DTOs\ProjectMarginSourceSnapshotRequest;

final class ProjectMarginReportSourceSnapshotAdapter extends AbstractBudgetingReportSourceSnapshotAdapter
{
    public function __construct(
        private readonly ProjectMarginSourceSnapshotWriter $writer,
        ReportSourceSnapshotStore $store,
    ) {
        parent::__construct($store);
    }

    protected function persistSourceSnapshot(ReportQuery $query): ReportSourceSnapshotHeader
    {
        return $this->writer->persist(new ProjectMarginSourceSnapshotRequest(
            $query->scope,
            $query->filters->values,
            $this->closeId($query),
            $this->closeIdentity($query),
            $query->asOf,
            null,
        ));
    }

    protected function reportCode(): string { return ProjectMarginSourceSnapshotMaterializer::REPORT_CODE; }

    protected function sourceKind(): string { return ProjectMarginSourceSnapshotMaterializer::SOURCE_KIND; }

    protected function schemaVersion(): string { return ProjectMarginSourceSnapshotMaterializer::SCHEMA_VERSION; }

    protected function drillColumnId(): string { return ProjectMarginSourceSnapshotMaterializer::DRILL_COLUMN_ID; }

    protected function rowSchema(): array
    {
        return array_map(static fn (string $id): array => ['id' => $id], [
            'actual', 'currency', 'forecast', 'group', 'plan', 'problem_flags', 'quality_status', 'risk_flags',
            'row_key', 'source_rows_count', 'source_types', 'variance',
        ]);
    }

    private function closeId(ReportQuery $query): string
    {
        $value = $query->filters->values['close_id'] ?? null;
        if (! is_string($value)) {
            throw new \InvalidArgumentException('project_margin_source_snapshot_close_invalid');
        }

        return $value;
    }

    private function closeIdentity(ReportQuery $query): BudgetingReportSourceCloseIdentity
    {
        $filters = $query->filters->values;

        return new BudgetingReportSourceCloseIdentity(
            $query->scope->organizationId,
            $this->stringFilter($filters, 'period_start'),
            $this->stringFilter($filters, 'period_end'),
            $this->stringFilter($filters, 'scenario_uuid'),
            $this->stringFilter($filters, 'budget_version_uuid'),
        );
    }

    private function stringFilter(array $filters, string $key): string
    {
        $value = $filters[$key] ?? null;
        if (! is_string($value)) {
            throw new \InvalidArgumentException('project_margin_source_snapshot_filter_invalid');
        }

        return $value;
    }
}
