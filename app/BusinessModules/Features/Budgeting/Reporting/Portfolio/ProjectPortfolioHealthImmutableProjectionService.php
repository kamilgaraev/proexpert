<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\Portfolio;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProgress;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportFreshnessStatus;
use App\BusinessModules\Features\Budgeting\DTOs\CfoCommandCenterFilters;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\DTO\ProjectPortfolioHealthRow;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\DTO\ProjectPortfolioProjectionResult;
use App\BusinessModules\Features\Budgeting\Services\CfoProjectPortfolioAggregator;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ProjectPortfolioHealthImmutableProjectionService
{
    public function __construct(
        private ProjectPortfolioHealthImmutableSource $sources,
        private BudgetingPortfolioProjectionService $snapshots,
        private CfoProjectPortfolioAggregator $aggregator,
        private ProjectPortfolioHealthRuntimeFilter $runtimeFilters,
    ) {}

    public function materialize(
        ReportExecutionContext $context,
        ReportQuery $query,
        ReportProgress $progress,
    ): ReportSnapshotRef {
        $this->assertQuery($context, $query);
        $projectIds = $this->effectiveProjectIds($context, $query);
        $selection = $this->sources->load($context, $query);
        try {
            $projects = $selection->projects();
        } catch (InvalidArgumentException) {
            $this->unavailable();
        }
        if (! isset($projects) || array_keys($projects) !== $projectIds) {
            $this->unavailable();
        }
        $values = $query->filters->values;
        $projects = $this->runtimeFilters->projects(
            $projects,
            array_key_exists('manager_ids', $values) ? $values['manager_ids'] : [],
            array_key_exists('project_statuses', $values) ? $values['project_statuses'] : [],
        );
        $filters = $this->filters($context, $query, $projectIds);
        $progress->advance(20);
        $margin = $selection->ownerPayloads['project_margin'];
        $progress->advance(40);
        $wip = $selection->ownerPayloads['wip_completion_forecast'];
        $planFact = $selection->ownerPayloads['budget_plan_fact'];
        $progress->advance(60);

        $projection = $this->aggregator->buildResult(
            $filters,
            $projects,
            $margin,
            $wip,
            $planFact['rows'],
            $selection->calendar,
            $query->asOf->format(DATE_ATOM),
            max(1, count($projects)),
            seedProjects: false,
        );
        $riskRowIndexes = $this->runtimeFilters->riskRowIndexes(
            array_map(
                static fn (ProjectPortfolioHealthRow $row): string => $row->riskLevel,
                $projection->rows,
            ),
            array_key_exists('risk_levels', $values) ? $values['risk_levels'] : [],
        );
        if (count($riskRowIndexes) !== count($projection->rows)) {
            $projection = ProjectPortfolioProjectionResult::fromRows(array_map(
                static fn (int $index): ProjectPortfolioHealthRow => $projection->rows[$index],
                $riskRowIndexes,
            ), $query->asOf->format(DATE_ATOM), max(1, count($projects)));
        }
        try {
            $snapshot = $this->snapshots->persistHealth(
                $context,
                $query,
                $projection,
                $selection->sourceHash(),
                $selection->watermarks(),
                $selection->sourceRefs(),
                ReportFreshnessStatus::FRESH,
                [],
            );
        } catch (InvalidArgumentException) {
            $this->unavailable();
        }
        $progress->advance(100);

        return $snapshot;
    }

    public function result(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
    ): ReportResult {
        return $this->snapshots->result(
            $context,
            $snapshot,
            BudgetingPortfolioProjectionService::HEALTH_CODE,
        );
    }

    private function assertQuery(ReportExecutionContext $context, ReportQuery $query): void
    {
        if ($query->definition->code !== BudgetingPortfolioProjectionService::HEALTH_CODE) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR);
        }
        if ($query->scope->canonicalIdentity() !== $context->scope->canonicalIdentity()) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN);
        }
    }

    private function filters(
        ReportExecutionContext $context,
        ReportQuery $query,
        array $projectIds,
    ): CfoCommandCenterFilters {
        $values = $query->filters->values;
        $periodStart = $this->dateFilter(
            $values['period_from'] ?? null,
            $query->asOf->format('Y-m-d'),
        );
        $periodEnd = $this->dateFilter(
            $values['period_to'] ?? null,
            $query->asOf->format('Y-m-d'),
        );
        if ($periodEnd < $periodStart) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_FILTER_RANGE_INVALID);
        }
        $responsibilityCenterIds = $this->positiveIds($values['responsibility_center_ids'] ?? []);
        $counterpartyIds = $this->positiveIds($values['counterparty_ids'] ?? []);

        return new CfoCommandCenterFilters(
            organizationId: $context->scope->organizationId,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            projectId: count($projectIds) === 1 ? $projectIds[0] : null,
            responsibilityCenterId: count($responsibilityCenterIds) === 1
                ? $responsibilityCenterIds[0]
                : null,
            counterpartyId: count($counterpartyIds) === 1 ? $counterpartyIds[0] : null,
            currency: $this->singleCurrency($values['currencies'] ?? null),
            itemLimit: 50,
        );
    }

    private function effectiveProjectIds(ReportExecutionContext $context, ReportQuery $query): array
    {
        $scopeIds = $context->scope->projectIds;
        if ($scopeIds === []) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN);
        }
        $filterIds = $this->positiveIds($query->filters->values['project_ids'] ?? []);
        if ($filterIds === []) {
            return $scopeIds;
        }
        $effectiveIds = array_values(array_intersect($scopeIds, $filterIds));
        if (count($effectiveIds) !== count($filterIds)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN);
        }

        return $effectiveIds;
    }

    private function positiveIds(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }
        $ids = [];
        foreach ($value as $id) {
            if ((is_int($id) || (is_string($id) && ctype_digit($id))) && (int) $id > 0) {
                $ids[(int) $id] = (int) $id;
            }
        }
        $ids = array_values($ids);
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    private function strings(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            static fn (mixed $item): bool => is_string($item) && trim($item) !== '',
        ));
    }

    private function firstString(mixed $value): ?string
    {
        return $this->strings($value)[0] ?? null;
    }

    private function singleCurrency(mixed $value): ?string
    {
        $currencies = $this->strings($value);

        return count($currencies) === 1 ? mb_strtoupper($currencies[0]) : null;
    }

    private function dateFilter(mixed $value, string $default): string
    {
        $candidate = is_string($value) ? $value : $this->firstString($value);
        if ($candidate === null) {
            return $default;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $candidate);
        if (! $date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $candidate) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_FILTER_RANGE_INVALID);
        }

        return $candidate;
    }

    private function unavailable(): never
    {
        throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
    }
}
