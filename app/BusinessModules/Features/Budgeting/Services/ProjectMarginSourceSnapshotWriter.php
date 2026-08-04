<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Services;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSourceSnapshotStreamingStore;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotHeader;
use App\BusinessModules\Features\Budgeting\Contracts\ProjectMarginSourceSnapshotReport;
use App\BusinessModules\Features\Budgeting\DTOs\ProjectMarginSourceSnapshotRequest;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ProjectMarginSourceSnapshotWriter
{
    public function __construct(
        private readonly ProjectMarginSourceSnapshotReport $projectMarginReport,
        private readonly ProjectMarginSourceSnapshotMaterializer $materializer,
        private readonly ReportSourceSnapshotStreamingStore $store,
        private readonly BudgetingReportSourceCloseService $closeService,
    ) {}

    public function persist(ProjectMarginSourceSnapshotRequest $request): ReportSourceSnapshotHeader
    {
        $close = $this->closeService->validatedCloseForReporting(
            $request->closeId,
            ProjectMarginSourceSnapshotMaterializer::REPORT_CODE,
            $request->closeIdentity,
            $request->asOf,
        );
        $filters = $this->normalizeFilters($request);
        $identity = $this->materializer->identity($request->scope, $filters, $close->closeId, $request->reportQueryIdentity);
        $ready = $this->store->findReady($identity);
        if ($ready !== null) {
            return $ready;
        }

        $report = $this->projectMarginReport->reportForProjectScope($filters, $request->scope->projectIds);
        $snapshot = $this->materializer->stream(
            $request->snapshotId ?? (string) Str::ulid(),
            $request->scope,
            $filters,
            $report,
            fn (string $drillKey): \Generator => $this->drillItems($filters, $request->scope->projectIds, $drillKey),
            $request->asOf,
            $request->staleAt,
            $close,
            $request->reportQueryIdentity,
        );

        return $this->store->resolveReadyStreamed($identity, $snapshot);
    }

    private function normalizeFilters(ProjectMarginSourceSnapshotRequest $request): array
    {
        $filters = $request->filters;
        if (($filters['organization_id'] ?? null) !== $request->scope->organizationId) {
            throw new InvalidArgumentException('project_margin_source_snapshot_scope_invalid');
        }

        $projectId = $filters['project_id'] ?? null;
        if ($request->scope->projectIds !== [] && $projectId !== null && ! in_array((int) $projectId, $request->scope->projectIds, true)) {
            throw new InvalidArgumentException('project_margin_source_snapshot_scope_invalid');
        }

        $filters['_skip_data_mart_meta'] = true;

        return $filters;
    }

    /** @return \Generator<int, mixed> */
    private function drillItems(array $filters, array $projectIds, string $drillKey): \Generator
    {
        $page = 1;
        $seen = 0;
        do {
            $drill = $this->projectMarginReport->drillDownForProjectScope([
                ...$filters,
                'drill_down_key' => $drillKey,
                'page' => $page,
                'per_page' => 500,
            ], $projectIds);
            $pageItems = $drill['items'] ?? null;
            $total = $drill['meta']['total'] ?? null;
            if (! is_array($pageItems) || ! array_is_list($pageItems) || ! is_int($total)) {
                throw new InvalidArgumentException('project_margin_source_snapshot_drill_invalid');
            }
            if ($pageItems === [] && $seen < $total) {
                throw new InvalidArgumentException('project_margin_source_snapshot_drill_invalid');
            }
            foreach ($pageItems as $item) {
                $seen++;
                if ($seen > $total) {
                    throw new InvalidArgumentException('project_margin_source_snapshot_drill_invalid');
                }
                yield $item;
            }
            $page++;
        } while ($seen < $total);
    }
}
