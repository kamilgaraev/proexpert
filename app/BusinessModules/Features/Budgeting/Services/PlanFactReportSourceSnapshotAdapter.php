<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Services;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSourceSnapshotStore;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotHeader;
use App\BusinessModules\Features\Budgeting\DTOs\BudgetingReportSourceCloseIdentity;
use App\BusinessModules\Features\Budgeting\DTOs\PlanFactSourceSnapshotRequest;

final class PlanFactReportSourceSnapshotAdapter extends AbstractBudgetingReportSourceSnapshotAdapter
{
    public function __construct(
        private readonly PlanFactSourceSnapshotWriter $writer,
        private readonly BudgetingReportSourceCloseService $closeService,
        ReportSourceSnapshotStore $store,
    ) {
        parent::__construct($store);
    }

    protected function persistSourceSnapshot(ReportQuery $query): ReportSourceSnapshotHeader
    {
        return $this->writer->persist(new PlanFactSourceSnapshotRequest(
            $query->scope,
            $query->filters->values,
            $this->closeId($query),
            $this->closeIdentity($query),
            $query->asOf,
            null,
        ));
    }

    protected function approvedCloseFormulaVersion(ReportQuery $query): string
    {
        return $this->closeService->validatedCloseForReporting(
            $this->closeId($query),
            $this->closeIdentity($query),
            $query->asOf,
        )->formulaVersion;
    }

    protected function reportCode(): string { return PlanFactSourceSnapshotMaterializer::REPORT_CODE; }

    protected function sourceKind(): string { return PlanFactSourceSnapshotMaterializer::SOURCE_KIND; }

    protected function schemaVersion(): string { return PlanFactSourceSnapshotMaterializer::SCHEMA_VERSION; }

    protected function drillColumnId(): string { return PlanFactSourceSnapshotMaterializer::DRILL_COLUMN_ID; }

    protected function rowSchema(): array
    {
        return array_map(static fn (string $id): array => ['id' => $id], [
            'actual_amount', 'committed_amount', 'currency', 'forecast_amount', 'group', 'plan_amount', 'risk_level',
            'row_key', 'variance_amount', 'variance_percent',
        ]);
    }

    private function closeId(ReportQuery $query): string
    {
        $value = $query->filters->values['close_id'] ?? null;
        if (! is_string($value)) {
            throw new \InvalidArgumentException('plan_fact_source_snapshot_close_invalid');
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
            throw new \InvalidArgumentException('plan_fact_source_snapshot_filter_invalid');
        }

        return $value;
    }
}
