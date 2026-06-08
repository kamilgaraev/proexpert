<?php

declare(strict_types=1);

namespace Tests\Unit\Budgeting;

use App\BusinessModules\Features\Budgeting\DTOs\CashGapForecastContext;
use App\BusinessModules\Features\Budgeting\DTOs\CashGapForecastFilters;
use App\BusinessModules\Features\Budgeting\DTOs\CashGapForecastItem;
use App\BusinessModules\Features\Budgeting\DTOs\CashGapScenarioAdjustment;
use App\BusinessModules\Features\Budgeting\Services\CashGapForecastService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CashGapForecastServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container();
        $loader = new FileLoader(new Filesystem(), dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'lang');
        $translator = new Translator($loader, 'ru');

        $container->instance('translator', $translator);
        $container->instance('config', new Repository([
            'app' => [
                'locale' => 'ru',
                'fallback_locale' => 'ru',
            ],
        ]));
        $container->instance('app', new class {
            public function getLocale(): string
            {
                return 'ru';
            }
        });

        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function test_empty_input_returns_valid_forecast_range(): void
    {
        $result = $this->service()->forecast(
            new CashGapForecastContext(
                periodStart: '2026-01-01',
                periodEnd: '2026-01-03',
                openingBalance: 1_000.0,
                filters: new CashGapForecastFilters(organizationId: 42),
            ),
            []
        )->toArray();

        $this->assertSame(['from' => '2026-01-01', 'to' => '2026-01-03'], $result['period']);
        $this->assertCount(3, $result['daily']);
        $this->assertSame(1_000.0, $result['closing_balance']);
        $this->assertFalse($result['cash_gap']['has_gap']);
        $this->assertSame(CashGapForecastService::RISK_LOW, $result['risk_level']);
        $this->assertSame(0, $result['meta']['included_items']);
    }

    public function test_multiple_payments_partial_remaining_and_reschedule_are_calculated_by_day(): void
    {
        $result = $this->service()->forecast(
            $this->context(openingBalance: 500.0, periodEnd: '2026-01-03'),
            [
                $this->item('2026-01-02', CashGapForecastItem::DIRECTION_INFLOW, CashGapForecastItem::BUCKET_PLANNED_INFLOW, 1_000.0),
                $this->item('2026-01-02', CashGapForecastItem::DIRECTION_OUTFLOW, CashGapForecastItem::BUCKET_SCHEDULED_OUTFLOW, 400.0, sourceId: 118),
                $this->item('2026-01-02', CashGapForecastItem::DIRECTION_OUTFLOW, CashGapForecastItem::BUCKET_RESERVED_OUTFLOW, 300.0, sourceId: 119),
                $this->item(
                    '2026-01-03',
                    CashGapForecastItem::DIRECTION_OUTFLOW,
                    CashGapForecastItem::BUCKET_APPROVED_OUTFLOW,
                    200.0,
                    sourceId: 120,
                    originalDate: '2026-01-01',
                ),
            ]
        )->toArray();

        $this->assertSame(800.0, $result['daily'][1]['closing_balance']);
        $this->assertSame(600.0, $result['closing_balance']);
        $this->assertSame(1_000.0, $result['inflows']);
        $this->assertSame(600.0, $result['outflows']);
        $this->assertSame(300.0, $result['reserved_outflows']);
        $this->assertSame('2026-01-01', $result['daily'][2]['drivers'][0]['original_date']);
    }

    public function test_overdue_inflows_are_risk_only_and_overdue_outflows_reduce_start_day_balance(): void
    {
        $result = $this->service()->forecast(
            $this->context(periodStart: '2026-01-05', periodEnd: '2026-01-05', openingBalance: 500.0),
            [
                $this->item('2026-01-01', CashGapForecastItem::DIRECTION_INFLOW, CashGapForecastItem::BUCKET_OVERDUE_INFLOW, 1_000.0),
                $this->item('2026-01-02', CashGapForecastItem::DIRECTION_OUTFLOW, CashGapForecastItem::BUCKET_OVERDUE_OUTFLOW, 700.0),
            ]
        )->toArray();

        $this->assertSame(0.0, $result['daily'][0]['inflows']);
        $this->assertSame(1_000.0, $result['daily'][0]['overdue_inflows']);
        $this->assertSame(700.0, $result['daily'][0]['overdue_outflows']);
        $this->assertSame(-200.0, $result['daily'][0]['closing_balance']);
        $this->assertSame(200.0, $result['cash_gap']['max_gap_amount']);
        $this->assertSame(CashGapForecastService::RISK_CRITICAL, $result['risk_level']);
    }

    public function test_stress_scenario_delays_probable_inflow_and_changes_gap_date(): void
    {
        $base = $this->service()->forecast(
            $this->context(periodEnd: '2026-01-10', openingBalance: 0.0),
            $this->scenarioItems()
        )->toArray();

        $stress = $this->service()->forecast(
            $this->context(
                periodEnd: '2026-01-10',
                openingBalance: 0.0,
                scenario: CashGapForecastContext::SCENARIO_STRESS,
            ),
            $this->scenarioItems()
        )->toArray();

        $this->assertFalse($base['cash_gap']['has_gap']);
        $this->assertTrue($stress['cash_gap']['has_gap']);
        $this->assertSame('2026-01-02', $stress['cash_gap']['first_gap_date']);
        $this->assertSame(750.0, $stress['inflows']);
        $this->assertSame(-50.0, $stress['closing_balance']);
    }

    public function test_organization_filter_excludes_foreign_items(): void
    {
        $result = $this->service()->forecast(
            $this->context(periodEnd: '2026-01-01', openingBalance: 100.0),
            [
                $this->item('2026-01-01', CashGapForecastItem::DIRECTION_OUTFLOW, CashGapForecastItem::BUCKET_APPROVED_OUTFLOW, 30.0),
                $this->item(
                    '2026-01-01',
                    CashGapForecastItem::DIRECTION_OUTFLOW,
                    CashGapForecastItem::BUCKET_APPROVED_OUTFLOW,
                    500.0,
                    organizationId: 7,
                ),
            ]
        )->toArray();

        $this->assertSame(70.0, $result['closing_balance']);
        $this->assertSame(1, $result['meta']['included_items']);
        $this->assertSame(1, $result['meta']['excluded_items']);
    }

    public function test_context_requires_organization_scope(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Выберите организацию для прогноза.');

        $this->service()->forecast(
            new CashGapForecastContext(
                periodStart: '2026-01-01',
                periodEnd: '2026-01-01',
                openingBalance: 100.0,
            ),
            []
        );
    }

    public function test_duplicate_cash_flow_key_prefers_scheduled_outflow_over_reservation(): void
    {
        $result = $this->service()->forecast(
            $this->context(periodEnd: '2026-01-01', openingBalance: 1_000.0),
            [
                $this->item(
                    '2026-01-01',
                    CashGapForecastItem::DIRECTION_OUTFLOW,
                    CashGapForecastItem::BUCKET_RESERVED_OUTFLOW,
                    400.0,
                    cashFlowKey: 'payment-document:100',
                ),
                $this->item(
                    '2026-01-01',
                    CashGapForecastItem::DIRECTION_OUTFLOW,
                    CashGapForecastItem::BUCKET_SCHEDULED_OUTFLOW,
                    400.0,
                    cashFlowKey: 'payment-document:100',
                ),
            ]
        )->toArray();

        $this->assertSame(600.0, $result['closing_balance']);
        $this->assertSame(400.0, $result['outflows']);
        $this->assertSame(0.0, $result['reserved_outflows']);
        $this->assertSame(1, $result['meta']['included_items']);
        $this->assertSame(1, $result['meta']['excluded_items']);
        $this->assertSame('payment-document:100', $result['daily'][0]['drivers'][0]['cash_flow_key']);
    }

    public function test_stress_scenario_driver_keeps_effective_probability_and_original_date(): void
    {
        $result = $this->service()->forecast(
            $this->context(
                periodEnd: '2026-01-10',
                scenario: CashGapForecastContext::SCENARIO_STRESS,
            ),
            [
                $this->item(
                    '2026-01-01',
                    CashGapForecastItem::DIRECTION_INFLOW,
                    CashGapForecastItem::BUCKET_PLANNED_INFLOW,
                    1_000.0,
                    probability: 0.8,
                ),
            ]
        )->toArray();

        $driver = $result['daily'][7]['drivers'][0];

        $this->assertSame('2026-01-08', $driver['date']);
        $this->assertSame('2026-01-01', $driver['original_date']);
        $this->assertSame(0.6, $driver['probability']);
        $this->assertSame(0.8, $driver['original_probability']);
        $this->assertSame(600.0, $driver['amount']);
    }

    public function test_custom_scenario_adjustments_do_not_change_base_items_and_are_compared_with_base(): void
    {
        $items = [
            $this->item(
                '2026-01-02',
                CashGapForecastItem::DIRECTION_OUTFLOW,
                CashGapForecastItem::BUCKET_APPROVED_OUTFLOW,
                800.0,
                sourceId: 201,
                cashFlowKey: 'payment-document:201',
            ),
            $this->item(
                '2026-01-03',
                CashGapForecastItem::DIRECTION_INFLOW,
                CashGapForecastItem::BUCKET_PLANNED_INFLOW,
                500.0,
                probability: 0.4,
                sourceId: 202,
                cashFlowKey: 'payment-document:202',
            ),
            $this->item(
                '2026-01-02',
                CashGapForecastItem::DIRECTION_OUTFLOW,
                CashGapForecastItem::BUCKET_APPROVED_OUTFLOW,
                300.0,
                sourceId: 203,
                cashFlowKey: 'payment-document:203',
            ),
        ];

        $base = $this->service()->forecast(
            $this->context(periodEnd: '2026-01-05', openingBalance: 200.0),
            $items,
        )->toArray();

        $scenario = $this->service()->forecast(
            new CashGapForecastContext(
                periodStart: '2026-01-01',
                periodEnd: '2026-01-05',
                openingBalance: 200.0,
                scenario: CashGapForecastContext::SCENARIO_CUSTOM,
                filters: new CashGapForecastFilters(
                    organizationId: 42,
                    budgetArticleId: 'article-uuid',
                    responsibilityCenterId: 'center-uuid',
                    currency: 'RUB',
                ),
                scenarioAdjustments: [
                    new CashGapScenarioAdjustment(
                        action: CashGapScenarioAdjustment::ACTION_RESCHEDULE_PAYMENT,
                        cashFlowKey: 'payment-document:201',
                        date: '2026-01-05',
                    ),
                    new CashGapScenarioAdjustment(
                        action: CashGapScenarioAdjustment::ACTION_CHANGE_INFLOW_PROBABILITY,
                        cashFlowKey: 'payment-document:202',
                        probability: 1.0,
                    ),
                    new CashGapScenarioAdjustment(
                        action: CashGapScenarioAdjustment::ACTION_EXCLUDE_PAYMENT,
                        cashFlowKey: 'payment-document:203',
                    ),
                    new CashGapScenarioAdjustment(
                        action: CashGapScenarioAdjustment::ACTION_ADD_TEMPORARY_FINANCING,
                        date: '2026-01-02',
                        amount: 400.0,
                        currency: 'RUB',
                        description: 'Краткосрочное финансирование',
                    ),
                ],
            ),
            $items,
        )->toArray();

        $this->assertTrue($base['cash_gap']['has_gap']);
        $this->assertFalse($scenario['cash_gap']['has_gap']);
        $this->assertSame('2026-01-02', $base['cash_gap']['first_gap_date']);
        $this->assertNull($scenario['cash_gap']['first_gap_date']);
        $this->assertSame('2026-01-02', $items[0]->date);
        $this->assertSame(1.0, $scenario['daily'][2]['drivers'][0]['probability']);
        $this->assertSame('2026-01-02', $scenario['daily'][4]['drivers'][0]['original_date']);
        $this->assertSame('cash_gap_scenario_adjustment', $scenario['daily'][1]['drivers'][0]['source']['type']);
    }

    public function test_cash_gap_signals_include_gap_minimum_deficit_drivers_overdue_inflows_and_drill_down(): void
    {
        $result = $this->service()->forecast(
            $this->context(periodEnd: '2026-01-01', openingBalance: 100.0),
            [
                $this->item(
                    '2025-12-30',
                    CashGapForecastItem::DIRECTION_INFLOW,
                    CashGapForecastItem::BUCKET_OVERDUE_INFLOW,
                    1_000.0,
                    sourceId: 300,
                    cashFlowKey: 'payment-document:300',
                    drillDown: ['document_href' => '/payments?tab=documents&document_id=300'],
                ),
                $this->item(
                    '2026-01-01',
                    CashGapForecastItem::DIRECTION_OUTFLOW,
                    CashGapForecastItem::BUCKET_APPROVED_OUTFLOW,
                    400.0,
                    sourceId: 301,
                    cashFlowKey: 'payment-document:301',
                    drillDown: ['document_href' => '/payments?tab=documents&document_id=301'],
                ),
            ],
        )->toArray();

        $this->assertSame('2026-01-01', $result['signals']['first_gap']['date']);
        $this->assertSame(300.0, $result['signals']['deficit']['amount']);
        $this->assertSame(-300.0, $result['signals']['minimum_balance']['amount']);
        $this->assertCount(2, $result['signals']['payment_drivers']);
        $this->assertCount(1, $result['signals']['overdue_inflows']);
        $this->assertSame(
            '/payments?tab=documents&document_id=301',
            $result['signals']['payment_drivers'][0]['drill_down']['document_href'],
        );
    }

    public function test_currency_filter_prevents_mixing_amounts_between_currencies(): void
    {
        $result = $this->service()->forecast(
            $this->context(periodEnd: '2026-01-01', openingBalance: 500.0),
            [
                $this->item(
                    '2026-01-01',
                    CashGapForecastItem::DIRECTION_OUTFLOW,
                    CashGapForecastItem::BUCKET_APPROVED_OUTFLOW,
                    100.0,
                    currency: 'RUB',
                ),
                $this->item(
                    '2026-01-01',
                    CashGapForecastItem::DIRECTION_OUTFLOW,
                    CashGapForecastItem::BUCKET_APPROVED_OUTFLOW,
                    900.0,
                    currency: 'USD',
                ),
            ],
        )->toArray();

        $this->assertSame(400.0, $result['closing_balance']);
        $this->assertSame(1, $result['meta']['included_items']);
        $this->assertSame(1, $result['meta']['excluded_items']);
        $this->assertFalse($result['cash_gap']['has_gap']);
    }

    public function test_overall_drivers_exclude_positive_inflows_from_risk_causes(): void
    {
        $result = $this->service()->forecast(
            $this->context(periodEnd: '2026-01-01', openingBalance: 0.0),
            [
                $this->item('2026-01-01', CashGapForecastItem::DIRECTION_INFLOW, CashGapForecastItem::BUCKET_PLANNED_INFLOW, 100.0),
                $this->item('2026-01-01', CashGapForecastItem::DIRECTION_OUTFLOW, CashGapForecastItem::BUCKET_APPROVED_OUTFLOW, 300.0),
            ]
        )->toArray();

        $this->assertSame(300.0, $result['drivers'][0]['amount']);
        $this->assertSame(CashGapForecastItem::BUCKET_APPROVED_OUTFLOW, $result['drivers'][0]['type']);
        $this->assertCount(1, $result['drivers']);
    }

    private function service(): CashGapForecastService
    {
        return new CashGapForecastService();
    }

    private function context(
        string $periodStart = '2026-01-01',
        string $periodEnd = '2026-01-02',
        float $openingBalance = 1_000.0,
        string $scenario = CashGapForecastContext::SCENARIO_BASE,
    ): CashGapForecastContext {
        return new CashGapForecastContext(
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            openingBalance: $openingBalance,
            scenario: $scenario,
            filters: new CashGapForecastFilters(
                organizationId: 42,
                budgetArticleId: 'article-uuid',
                responsibilityCenterId: 'center-uuid',
                currency: 'RUB',
            ),
        );
    }

    private function item(
        string $date,
        string $direction,
        string $bucket,
        float $amount,
        float $probability = 1.0,
        ?int $organizationId = 42,
        ?int $projectId = null,
        ?int $counterpartyId = null,
        ?string $budgetArticleId = 'article-uuid',
        ?string $responsibilityCenterId = 'center-uuid',
        int|string|null $sourceId = null,
        ?string $originalDate = null,
        ?string $cashFlowKey = null,
        string $currency = 'RUB',
        array $drillDown = [],
    ): CashGapForecastItem {
        return new CashGapForecastItem(
            date: $date,
            direction: $direction,
            bucket: $bucket,
            amount: $amount,
            probability: $probability,
            organizationId: $organizationId,
            projectId: $projectId,
            counterpartyId: $counterpartyId,
            budgetArticleId: $budgetArticleId,
            responsibilityCenterId: $responsibilityCenterId,
            currency: $currency,
            sourceType: 'payment_document',
            sourceId: $sourceId,
            originalDate: $originalDate,
            cashFlowKey: $cashFlowKey,
            drillDown: $drillDown,
        );
    }

    /**
     * @return list<CashGapForecastItem>
     */
    private function scenarioItems(): array
    {
        return [
            $this->item('2026-01-01', CashGapForecastItem::DIRECTION_INFLOW, CashGapForecastItem::BUCKET_PLANNED_INFLOW, 1_000.0),
            $this->item('2026-01-02', CashGapForecastItem::DIRECTION_OUTFLOW, CashGapForecastItem::BUCKET_APPROVED_OUTFLOW, 800.0),
        ];
    }
}
