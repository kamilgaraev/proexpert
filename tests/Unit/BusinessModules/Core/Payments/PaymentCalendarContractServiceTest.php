<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\Core\Payments;

use App\BusinessModules\Core\Payments\DTOs\PaymentCalendarItem;
use App\BusinessModules\Core\Payments\DTOs\PaymentCalendarCashGapOptions;
use App\BusinessModules\Core\Payments\DTOs\PaymentCalendarSourceFilters;
use App\BusinessModules\Core\Payments\Services\PaymentCalendarContractService;
use App\BusinessModules\Core\Payments\Services\PaymentCalendarSourceService;
use App\BusinessModules\Features\Budgeting\Services\CashGapForecastService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\TestCase;

final class PaymentCalendarContractServiceTest extends TestCase
{
    private PaymentCalendarContractService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container();
        $loader = new FileLoader(new Filesystem(), dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'lang');
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

        $this->service = new PaymentCalendarContractService(
            new PaymentCalendarSourceService(),
            new CashGapForecastService(),
        );
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function test_contract_contains_items_events_daily_aggregates_summary_and_cash_gap_state(): void
    {
        $contract = $this->service->fromItems([
            new PaymentCalendarItem(
                organizationId: 42,
                date: '2026-01-10',
                originalDate: null,
                direction: PaymentCalendarItem::DIRECTION_OUTFLOW,
                bucket: PaymentCalendarItem::BUCKET_SCHEDULED,
                amount: 1_000.0,
                remainingAmount: 700.0,
                currency: 'RUB',
                probability: 1.0,
                status: 'scheduled',
                sourceType: 'payment_document',
                sourceId: 118,
                cashFlowKey: 'payment-document:118',
                projectId: 14,
                counterpartyId: 30,
                budgetArticleId: 77,
                responsibilityCenterId: 88,
                editable: true,
                drillDown: [
                    'type' => 'payment_document',
                    'id' => 118,
                    'document_number' => 'PAY-118',
                    'label' => 'PAY-118',
                ],
            ),
            new PaymentCalendarItem(
                organizationId: 42,
                date: '2026-01-11',
                originalDate: null,
                direction: PaymentCalendarItem::DIRECTION_INFLOW,
                bucket: PaymentCalendarItem::BUCKET_FACT,
                amount: 400.0,
                remainingAmount: 400.0,
                currency: 'RUB',
                probability: 1.0,
                status: 'completed',
                sourceType: 'payment_transaction',
                sourceId: 301,
                cashFlowKey: 'payment-transaction:301',
                editable: false,
            ),
        ], new PaymentCalendarSourceFilters(
            organizationId: 42,
            periodStart: '2026-01-10',
            periodEnd: '2026-01-11',
        ));

        $this->assertCount(2, $contract['items']);
        $this->assertCount(2, $contract['events']);
        $this->assertSame('Оплата', $contract['items'][0]['direction_label']);
        $this->assertSame('По графику', $contract['items'][0]['bucket_label']);
        $this->assertSame(118, $contract['items'][0]['document_id']);
        $this->assertTrue($contract['events'][0]['editable']);
        $this->assertSame(700.0, $contract['days'][0]['outflow']);
        $this->assertSame(400.0, $contract['days'][1]['inflow']);
        $this->assertSame(2, $contract['summary']['items_count']);
        $this->assertSame(400.0, $contract['summary']['inflow']);
        $this->assertSame(700.0, $contract['summary']['outflow']);
        $this->assertSame(-300.0, $contract['summary']['net']);
        $this->assertFalse($contract['cash_gap']['available']);
        $this->assertSame(
            'Прогноз кассового разрыва недоступен: не задан утвержденный начальный остаток денежных средств.',
            $contract['cash_gap']['reason']
        );
    }

    public function test_contract_builds_cash_gap_forecast_when_opening_balance_is_provided(): void
    {
        $contract = $this->service->fromItems([
            new PaymentCalendarItem(
                organizationId: 42,
                date: '2026-01-10',
                originalDate: null,
                direction: PaymentCalendarItem::DIRECTION_OUTFLOW,
                bucket: PaymentCalendarItem::BUCKET_APPROVED,
                amount: 800.0,
                remainingAmount: 800.0,
                currency: 'RUB',
                probability: 1.0,
                status: 'approved',
                sourceType: 'payment_document',
                sourceId: 118,
                cashFlowKey: 'payment-document:118',
                editable: true,
            ),
            new PaymentCalendarItem(
                organizationId: 42,
                date: '2026-01-11',
                originalDate: null,
                direction: PaymentCalendarItem::DIRECTION_INFLOW,
                bucket: PaymentCalendarItem::BUCKET_SCHEDULED,
                amount: 1_000.0,
                remainingAmount: 1_000.0,
                currency: 'RUB',
                probability: 0.5,
                status: 'scheduled',
                sourceType: 'payment_document',
                sourceId: 119,
                cashFlowKey: 'payment-document:119',
                editable: true,
            ),
        ], new PaymentCalendarSourceFilters(
            organizationId: 42,
            periodStart: '2026-01-10',
            periodEnd: '2026-01-11',
        ), new PaymentCalendarCashGapOptions(openingBalance: 500.0));

        $this->assertTrue($contract['cash_gap']['available']);
        $this->assertNull($contract['cash_gap']['reason']);
        $this->assertSame(500.0, $contract['cash_gap']['opening_balance']);
        $this->assertSame('base', $contract['cash_gap']['scenario']);
        $this->assertSame('critical', $contract['cash_gap']['forecast']['risk_level']);
        $this->assertSame('2026-01-10', $contract['cash_gap']['forecast']['cash_gap']['first_gap_date']);
        $this->assertSame(300.0, $contract['cash_gap']['forecast']['cash_gap']['max_gap_amount']);
        $this->assertSame('payment-document:118', $contract['items'][0]['cash_flow_key']);
    }

    public function test_contract_applies_cash_gap_scenario_assumptions_without_changing_calendar_items(): void
    {
        $contract = $this->service->fromItems([
            new PaymentCalendarItem(
                organizationId: 42,
                date: '2026-01-10',
                originalDate: null,
                direction: PaymentCalendarItem::DIRECTION_OUTFLOW,
                bucket: PaymentCalendarItem::BUCKET_APPROVED,
                amount: 800.0,
                remainingAmount: 800.0,
                currency: 'RUB',
                probability: 1.0,
                status: 'approved',
                sourceType: 'payment_document',
                sourceId: 118,
                cashFlowKey: 'payment-document:118',
                editable: true,
            ),
            new PaymentCalendarItem(
                organizationId: 42,
                date: '2026-01-11',
                originalDate: null,
                direction: PaymentCalendarItem::DIRECTION_OUTFLOW,
                bucket: PaymentCalendarItem::BUCKET_APPROVED,
                amount: 200.0,
                remainingAmount: 200.0,
                currency: 'RUB',
                probability: 1.0,
                status: 'approved',
                sourceType: 'payment_document',
                sourceId: 119,
                cashFlowKey: 'payment-document:119',
                editable: true,
            ),
        ], new PaymentCalendarSourceFilters(
            organizationId: 42,
            periodStart: '2026-01-10',
            periodEnd: '2026-01-12',
        ), new PaymentCalendarCashGapOptions(
            openingBalance: 500.0,
            reschedules: [
                ['cash_flow_key' => 'payment-document:118', 'date' => '2026-01-12'],
            ],
            financingItems: [
                ['date' => '2026-01-10', 'amount' => 400.0, 'currency' => 'RUB'],
            ],
            excludedCashFlowKeys: ['payment-document:119'],
        ));

        $this->assertSame('2026-01-10', $contract['items'][0]['date']);
        $this->assertSame('2026-01-11', $contract['items'][1]['date']);
        $this->assertTrue($contract['cash_gap']['available']);
        $this->assertTrue($contract['cash_gap']['scenario_has_assumptions']);
        $this->assertSame(0.0, $contract['cash_gap']['forecast']['cash_gap']['max_gap_amount']);
        $this->assertSame(500.0, $contract['cash_gap']['baseline_forecast']['cash_gap']['max_gap_amount']);
        $this->assertSame(-500.0, $contract['cash_gap']['comparison']['max_gap_amount_delta']);
        $this->assertSame(3, $contract['cash_gap']['scenario_assumptions_count']);
    }
}
