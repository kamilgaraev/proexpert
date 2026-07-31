<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Services;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSourceSnapshotStore;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotHeader;
use App\BusinessModules\Features\Budgeting\Contracts\PlanFactSourceSnapshotReport;
use App\BusinessModules\Features\Budgeting\DTOs\PlanFactSourceSnapshotRequest;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class PlanFactSourceSnapshotWriter
{
    public function __construct(
        private readonly PlanFactSourceSnapshotReport $planFactReport,
        private readonly PlanFactSourceSnapshotMaterializer $materializer,
        private readonly ReportSourceSnapshotStore $store,
    ) {
    }

    public function persist(PlanFactSourceSnapshotRequest $request): ReportSourceSnapshotHeader
    {
        $filters = $this->normalizeFilters($request);
        $report = $this->planFactReport->reportForProjectScope($filters, $request->scope->projectIds);
        $drillsByKey = $this->drills($filters, $request->scope->projectIds, $report);
        $snapshot = $this->materializer->materialize(
            $request->snapshotId ?? (string) Str::ulid(),
            $request->scope,
            $filters,
            $report,
            $drillsByKey,
            $request->asOf,
            $request->staleAt,
        );

        return $this->store->persistReady($snapshot);
    }

    private function normalizeFilters(PlanFactSourceSnapshotRequest $request): array
    {
        $filters = $request->filters;
        if (($filters['organization_id'] ?? null) !== $request->scope->organizationId) {
            throw new InvalidArgumentException('plan_fact_source_snapshot_scope_invalid');
        }

        $projectId = $filters['project_id'] ?? null;
        if ($projectId !== null && !in_array((int) $projectId, $request->scope->projectIds, true)) {
            throw new InvalidArgumentException('plan_fact_source_snapshot_scope_invalid');
        }

        $filters['_skip_data_mart_meta'] = true;

        return $filters;
    }

    private function drills(array $filters, array $projectIds, array $report): array
    {
        $rows = $report['rows'] ?? null;
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new InvalidArgumentException('plan_fact_source_snapshot_rows_invalid');
        }

        $result = [];
        foreach ($rows as $row) {
            $drillKey = is_array($row) ? ($row['drill_down_key'] ?? null) : null;
            if (!is_string($drillKey) || $drillKey === '') {
                throw new InvalidArgumentException('plan_fact_source_snapshot_rows_invalid');
            }

            $result[$drillKey] = $this->allDrillItems($filters, $projectIds, $drillKey);
        }

        return $result;
    }

    private function allDrillItems(array $filters, array $projectIds, string $drillKey): array
    {
        $items = [];
        $page = 1;
        do {
            $drill = $this->planFactReport->drillDownForProjectScope([
                ...$filters,
                'drill_down_key' => $drillKey,
                'page' => $page,
                'per_page' => 500,
            ], $projectIds);
            $pageItems = $drill['items'] ?? null;
            $total = $drill['meta']['total'] ?? null;
            if (!is_array($pageItems) || !array_is_list($pageItems) || !is_int($total)) {
                throw new InvalidArgumentException('plan_fact_source_snapshot_drill_invalid');
            }
            array_push($items, ...$pageItems);
            $page++;
        } while (count($items) < $total);

        return $items;
    }
}
