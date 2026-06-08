<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\Core\Payments;

use App\BusinessModules\Core\Payments\DTOs\PaymentCalendarItem;
use App\BusinessModules\Core\Payments\DTOs\PaymentCalendarSourceFilters;
use App\BusinessModules\Core\Payments\Services\PaymentCalendarContractService;
use App\BusinessModules\Core\Payments\Services\PaymentCalendarSourceService;
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

        $this->service = new PaymentCalendarContractService(new PaymentCalendarSourceService());
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
}
