<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\WaveOne;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDataProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportRowQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportActor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProgress;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportVisibility;
use App\BusinessModules\Features\Budgeting\DTOs\CfoCommandCenterFilters;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\BudgetingPortfolioProjectionService;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\BudgetingPortfolioQueryService;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\DTO\PortfolioLiquidityRow;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\DTO\ProjectPortfolioHealthRow;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\DTO\ProjectPortfolioProjectionResult;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\PortfolioLiquidityProvider;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\ProjectPortfolioHealthProvider;
use App\BusinessModules\Features\Budgeting\Services\CfoProjectPortfolioAggregator;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\Reporting\ReportDefinitionBuilder;

final class BudgetingPortfolioSourceTest extends TestCase
{
    #[Test]
    public function portfolio_health_keeps_currency_totals_separate_and_uses_weighted_margin(): void
    {
        $result = ProjectPortfolioProjectionResult::fromRows([
            new ProjectPortfolioHealthRow(10, 'Проект 10', 'RUB', '150.00', '100.00', '0.00', '0.00', '0.00', '0.00', 'low', 1, '2026-07-29', []),
            new ProjectPortfolioHealthRow(11, 'Проект 11', 'RUB', '50.00', '40.00', '0.00', '0.00', '0.00', '0.00', 'low', 1, '2026-07-29', []),
            new ProjectPortfolioHealthRow(10, 'Проект 10', 'USD', '40.00', '25.00', '0.00', '0.00', '0.00', '0.00', 'low', 1, '2026-07-29', []),
        ], '2026-07-29T00:00:00+00:00', 100);

        self::assertSame('50.00', $result->row(10, 'RUB')->margin);
        self::assertSame('15.00', $result->row(10, 'USD')->margin);
        self::assertSame('30.00000000', $result->totalsByCurrency['RUB']['margin_percent']);
        self::assertSame('37.50000000', $result->totalsByCurrency['USD']['margin_percent']);
        self::assertCount(2, $result->totalsByCurrency);
    }

    #[Test]
    public function zero_revenue_has_null_margin_ratio(): void
    {
        $row = new ProjectPortfolioHealthRow(10, 'Проект 10', 'RUB', '0.00', '10.00', '0.00', '0.00', '0.00', '0.00', 'high', 3, '2026-07-29', []);

        self::assertSame('-10.00', $row->margin);
        self::assertNull($row->marginPercent);
    }

    #[Test]
    public function portfolio_money_does_not_lose_precision_above_float_integer_range(): void
    {
        $row = new ProjectPortfolioHealthRow(
            10,
            'Проект 10',
            'RUB',
            '900719925474099.91',
            '900719925474000.00',
            '0.00',
            '0.00',
            '0.00',
            '0.00',
            'low',
            1,
            '2026-07-29',
            [],
        );

        self::assertSame('99.91', $row->margin);
    }

    #[Test]
    public function liquidity_recurrence_and_normalized_source_keys_prevent_double_counting(): void
    {
        $rows = PortfolioLiquidityRow::recurring([
            [
                'forecast_date' => '2026-07-29',
                'inflows' => [['key' => 'payment-document:7', 'amount' => '30.00']],
                'outflows' => [
                    ['key' => 'payment-document:8', 'amount' => '20.00'],
                    ['key' => 'payment-document:8', 'amount' => '20.00'],
                    ['key' => 'payment-document:9', 'amount' => '10.00'],
                ],
            ],
            [
                'forecast_date' => '2026-07-30',
                'inflows' => [],
                'outflows' => [['key' => 'payment-document:10', 'amount' => '5.00']],
            ],
        ], 10, 'Проект 10', 'RUB', 'base', '100.00', []);

        self::assertSame('100.00', $rows[0]->closing);
        self::assertSame($rows[0]->closing, $rows[1]->opening);
        self::assertSame('95.00', $rows[1]->closing);
        self::assertSame(1, $rows[0]->duplicateSourceCount);
    }

    #[Test]
    public function aggregator_build_is_a_compatibility_adapter_over_typed_result(): void
    {
        $filters = new CfoCommandCenterFilters(1, '2026-07-01', '2026-07-31', currency: 'RUB');
        $projects = [10 => ['id' => 10, 'name' => 'Проект 10', 'status' => 'active']];
        $margin = ['rows' => [[
            'project_id' => 10,
            'currency' => 'RUB',
            'actual' => ['revenue' => 150.0, 'cost' => 100.0, 'gross_margin' => 50.0],
        ]]];

        $aggregator = new CfoProjectPortfolioAggregator;
        $typed = $aggregator->buildResult($filters, $projects, $margin, ['rows' => []], [], [], '2026-07-29T00:00:00+00:00', 10);

        self::assertSame(
            $typed->toArray(),
            $aggregator->build($filters, $projects, $margin, ['rows' => []], [], [], '2026-07-29T00:00:00+00:00', 10),
        );
        self::assertSame('50.00', $typed->row(10, 'RUB')->margin);
    }

    #[Test]
    public function health_row_keeps_real_margin_wip_plan_fact_limit_and_approval_sources(): void
    {
        $filters = new CfoCommandCenterFilters(1, '2026-07-01', '2026-07-31', currency: 'RUB');
        $projects = [10 => ['id' => 10, 'name' => 'Проект 10', 'status' => 'active']];
        $calendar = [new \App\BusinessModules\Core\Payments\DTOs\PaymentCalendarItem(
            1,
            '2026-07-20',
            null,
            'outflow',
            'reserved',
            '10.00',
            '10.00',
            'RUB',
            '1.0',
            'reserved',
            'budget_limit_reservation',
            'reservation-1',
            'reservation:1',
            10,
        )];
        $result = (new CfoProjectPortfolioAggregator)->buildResult(
            $filters,
            $projects,
            ['rows' => [[
                'project_id' => 10,
                'currency' => 'RUB',
                'actual' => ['revenue' => '100.00', 'cost' => '80.00'],
                'source_refs' => [['type' => 'approved_act', 'id' => 7]],
            ]]],
            ['rows' => [[
                'project_id' => 10,
                'currency' => 'RUB',
                'metrics' => [],
                'source_refs' => [['type' => 'earned_value', 'id' => 'wip-1']],
            ]]],
            [[
                'project_id' => 10,
                'currency' => 'RUB',
                'source_refs' => [['type' => 'budget_line', 'id' => 'line-1']],
            ]],
            $calendar,
            '2026-07-29T00:00:00+00:00',
            10,
        );

        self::assertSame([
            ['type' => 'approved_act', 'id' => 7],
            ['type' => 'budget_line', 'id' => 'line-1'],
            ['type' => 'budget_reservation', 'id' => 'reservation-1'],
            ['type' => 'earned_value', 'id' => 'wip-1'],
            ['type' => 'project', 'id' => 10],
        ], $result->row(10, 'RUB')->sourceRefs);
    }

    #[Test]
    public function project_portfolio_health_provider_contract(): void
    {
        self::assertContains(ReportDataProvider::class, class_implements(ProjectPortfolioHealthProvider::class));
        self::assertNotContains(ReportRowQuery::class, class_implements(ProjectPortfolioHealthProvider::class));
    }

    #[Test]
    public function portfolio_liquidity_provider_contract(): void
    {
        self::assertContains(ReportDataProvider::class, class_implements(PortfolioLiquidityProvider::class));
        self::assertContains(ReportRowQuery::class, class_implements(BudgetingPortfolioQueryService::class));
        self::assertContains(ReportDrillDownProvider::class, class_implements(BudgetingPortfolioQueryService::class));
    }

    #[Test]
    public function budgeting_owner_registers_all_non_payment_liquidity_sources_for_versioning(): void
    {
        $provider = (string) file_get_contents(
            dirname(__DIR__, 4).'/app/BusinessModules/Features/Budgeting/BudgetingServiceProvider.php',
        );

        foreach ([
            'BudgetAmount::observe(PortfolioLiquiditySourceVersionObserver::class)',
            'BudgetLimitReservation::observe(PortfolioLiquiditySourceVersionObserver::class)',
            'CashGapOpeningBalance::observe(PortfolioLiquiditySourceVersionObserver::class)',
            'BudgetVersion::observe(PortfolioLiquidityBudgetVersionObserver::class)',
        ] as $registration) {
            self::assertStringContainsString($registration, $provider);
        }
    }

    #[Test]
    public function production_materializer_requires_all_canonical_owner_sources_and_has_no_prepared_fallback(): void
    {
        $reflection = new ReflectionClass(BudgetingPortfolioProjectionService::class);
        $types = array_map(
            static fn (\ReflectionParameter $parameter): string => (string) $parameter->getType(),
            $reflection->getConstructor()?->getParameters() ?? [],
        );

        self::assertSame([
            'App\BusinessModules\Features\Budgeting\Services\ProjectMarginReportService',
            'App\BusinessModules\Features\Budgeting\Services\WipForecastReportService',
            'App\BusinessModules\Features\Budgeting\Services\PlanFactReportService',
            'App\BusinessModules\Core\Payments\Services\PaymentCalendarSourceService',
            'App\BusinessModules\Features\Budgeting\Services\CashGapOpeningBalanceService',
            'App\BusinessModules\Features\Budgeting\Services\CashGapForecastService',
            'App\BusinessModules\Features\Budgeting\Services\CfoProjectPortfolioAggregator',
        ], $types);
        self::assertFalse($reflection->hasMethod('materializePrepared'));
    }

    #[Test]
    public function production_materializer_rejects_project_filter_outside_authorized_scope(): void
    {
        $timezone = new DateTimeZone('UTC');
        $scope = new ReportScope(1, [1], [10], [], $timezone);
        $context = new ReportExecutionContext(
            new ReportActor(7, 'active', ['reports.view']),
            $scope,
            new ReportVisibility(true, false, false, false, false, false, false),
            new AuthorizationDecisionContext('http', 1, [1], [10], [], $timezone, 'scope-test', null),
        );
        $query = new ReportQuery(
            (new ReportDefinitionBuilder)->code(BudgetingPortfolioProjectionService::HEALTH_CODE)->payload(),
            $scope,
            new ReportFilterSet(['project_ids' => [11]]),
            [],
            new DateTimeImmutable('2026-07-29T00:00:00+00:00'),
            'ru',
        );
        $materializer = (new ReflectionClass(BudgetingPortfolioProjectionService::class))
            ->newInstanceWithoutConstructor();

        try {
            $materializer->materialize(
                $context,
                $query,
                new ReportProgress(0),
                BudgetingPortfolioProjectionService::HEALTH_CODE,
            );
            self::fail('Project filter outside authorized scope must be rejected before source access.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_SCOPE_FORBIDDEN, $exception->errorCode);
        }
    }

    #[Test]
    public function project_portfolio_health_fails_closed_until_every_owner_source_is_versioned(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4)
            .'/app/BusinessModules/Features/Budgeting/Reporting/Portfolio/BudgetingPortfolioProjectionService.php',
        );

        self::assertIsString($source);
        self::assertStringContainsString('assertImmutableHealthCoverage', $source);
        self::assertStringContainsString('ReportErrorCode::REPORT_SOURCE_UNAVAILABLE', $source);
    }
}
