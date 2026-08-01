<?php

declare(strict_types=1);

namespace Tests\Unit\Budgeting;

use App\BusinessModules\Features\Budgeting\DTOs\ProjectMarginReportFilters;
use App\BusinessModules\Features\Budgeting\Services\ProjectMarginCalculator;
use App\BusinessModules\Features\Budgeting\Services\ProjectMarginReportService;
use App\Domain\Authorization\Services\AuthorizationService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Database\Query\Processors\PostgresProcessor;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ProjectMarginProjectScopeFilterTest extends TestCase
{
    public function test_scoped_project_ids_are_applied_before_source_rows_are_read(): void
    {
        $query = $this->query();

        $this->apply($query, $this->filters([20, 10]));

        self::assertSame('select * from "project_margin_sources" where "project_id" in (?, ?)', $query->toSql());
        self::assertSame([20, 10], $query->getBindings());
    }

    public function test_empty_snapshot_scope_is_an_explicit_empty_source_set(): void
    {
        $query = $this->query();

        $this->apply($query, $this->filters([]));

        self::assertSame('select * from "project_margin_sources" where 1 = 0', $query->toSql());
        self::assertSame([], $query->getBindings());
    }

    public function test_legacy_unscoped_service_path_keeps_organization_wide_query_behavior(): void
    {
        $query = $this->query();

        $this->apply($query, $this->filters(null));

        self::assertSame('select * from "project_margin_sources"', $query->toSql());
        self::assertSame([], $query->getBindings());
    }

    private function apply(QueryBuilder $query, ProjectMarginReportFilters $filters): void
    {
        $method = new ReflectionMethod(ProjectMarginReportService::class, 'applyNormalizedFilters');
        $method->invoke($this->service(), $query, $filters);
    }

    private function query(): QueryBuilder
    {
        return (new QueryBuilder(
            $this->createStub(ConnectionInterface::class),
            new PostgresGrammar,
            new PostgresProcessor,
        ))->from('project_margin_sources');
    }

    private function service(): ProjectMarginReportService
    {
        return new ProjectMarginReportService(
            new ProjectMarginCalculator,
            $this->createStub(AuthorizationService::class),
        );
    }

    private function filters(?array $projectIds): ProjectMarginReportFilters
    {
        return new ProjectMarginReportFilters(
            organizationId: 1,
            periodStart: '2026-01-01',
            periodEnd: '2026-01-31',
            budgetVersionId: null,
            budgetVersionUuid: null,
            scenarioId: null,
            scenarioUuid: null,
            projectId: null,
            projectIds: $projectIds,
            contractId: null,
            responsibilityCenterId: null,
            responsibilityCenterUuid: null,
            budgetArticleId: null,
            budgetArticleUuid: null,
            counterpartyId: null,
            currency: null,
            groupBy: ['month', 'project', 'currency'],
        );
    }
}
