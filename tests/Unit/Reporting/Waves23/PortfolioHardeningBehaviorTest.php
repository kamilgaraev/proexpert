<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Core\MultiOrganization\Reporting\Queries\HoldingPerformanceRowQuery;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingPerformanceFormula;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingPerformanceSnapshotMaterializer;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportReconciliationStatus;
use App\BusinessModules\Features\Budgeting\DTOs\CashGapForecastContext;
use App\BusinessModules\Features\Budgeting\DTOs\CashGapForecastFilters;
use App\BusinessModules\Features\Budgeting\DTOs\CashGapForecastItem;
use App\BusinessModules\Features\Budgeting\DTOs\CashGapScenarioAdjustment;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\BudgetingPortfolioProjectionService;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\Models\BudgetingPortfolioSnapshot;
use App\BusinessModules\Features\Budgeting\Services\CashGapForecastService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class PortfolioHardeningBehaviorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container;
        $loader = new FileLoader(
            new Filesystem,
            dirname(__DIR__, 4).DIRECTORY_SEPARATOR.'lang',
        );
        $container->instance('translator', new Translator($loader, 'ru'));
        $container->instance('config', new Repository([
            'app' => ['locale' => 'ru', 'fallback_locale' => 'ru'],
        ]));
        $container->instance('app', new class
        {
            public function getLocale(): string
            {
                return 'ru';
            }
        });
        Facade::setFacadeApplication($container);
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);

        parent::tearDown();
    }

    #[Test]
    public function custom_probability_remains_decimal_through_scenario_calculation(): void
    {
        $adjustment = CashGapScenarioAdjustment::fromArray([
            'action' => CashGapScenarioAdjustment::ACTION_CHANGE_INFLOW_PROBABILITY,
            'cash_flow_key' => 'payment:10',
            'probability' => '0.123456785',
        ]);
        $result = (new CashGapForecastService)->forecast(
            new CashGapForecastContext(
                periodStart: '2026-07-30',
                periodEnd: '2026-07-30',
                openingBalance: '0',
                scenario: CashGapForecastContext::SCENARIO_CUSTOM,
                filters: new CashGapForecastFilters(organizationId: 1),
                scenarioAdjustments: [$adjustment],
            ),
            [
                new CashGapForecastItem(
                    date: '2026-07-30',
                    direction: CashGapForecastItem::DIRECTION_INFLOW,
                    bucket: CashGapForecastItem::BUCKET_PLANNED_INFLOW,
                    amount: '100.00',
                    probability: '1',
                    organizationId: 1,
                    cashFlowKey: 'payment:10',
                ),
            ],
        );

        self::assertSame('0.12345679', $adjustment->probability);
        self::assertSame('12.35', $result->days[0]->inflows);
        self::assertSame('0.12345679', $result->days[0]->drivers[0]['probability']);
    }

    #[Test]
    public function health_snapshot_exposes_incomplete_critical_source_coverage(): void
    {
        $snapshot = new BudgetingPortfolioSnapshot;
        $snapshot->setRawAttributes([
            'report_code' => BudgetingPortfolioProjectionService::HEALTH_CODE,
            'quality_status' => ReportQualityStatus::PARTIAL->value,
            'row_count' => 2,
            'watermarks' => json_encode([
                'quality_gaps' => ['margin_quality_attention'],
            ], JSON_THROW_ON_ERROR),
        ]);

        $quality = BudgetingPortfolioProjectionService::qualityFromRecord($snapshot);

        self::assertSame(ReportQualityStatus::PARTIAL, $quality->status);
        self::assertSame('2', $quality->coverage?->numerator);
        self::assertSame('3', $quality->coverage?->denominator);
        self::assertSame(1, $quality->unmatchedCount);
        self::assertSame(ReportReconciliationStatus::MISMATCH, $quality->reconciliation);
        self::assertSame('SOURCE_COVERAGE_PARTIAL', $quality->warnings[0]->code);
        self::assertSame(['margin_quality_attention'], $quality->excludedSources);
    }

    #[Test]
    public function allocation_drill_down_uses_contract_identity_for_contract_route(): void
    {
        $query = new HoldingPerformanceRowQuery(
            new HoldingPerformanceSnapshotMaterializer(new HoldingPerformanceFormula),
        );
        $method = new ReflectionMethod($query, 'routeParams');

        self::assertSame(
            ['id' => 44],
            $method->invoke($query, [
                'type' => 'contract_allocation',
                'id' => '991',
                'contract_id' => '44',
            ]),
        );
    }
}
