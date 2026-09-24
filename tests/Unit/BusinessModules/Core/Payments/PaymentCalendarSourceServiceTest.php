<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\Core\Payments;

use App\BusinessModules\Core\Payments\DTOs\PaymentCalendarItem;
use App\BusinessModules\Core\Payments\DTOs\PaymentCalendarSourceFilters;
use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentTransactionStatus;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Models\PaymentSchedule;
use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use App\BusinessModules\Core\Payments\Services\PaymentCalendarSourceService;
use App\BusinessModules\Features\Budgeting\DTOs\CashGapForecastContext;
use App\BusinessModules\Features\Budgeting\DTOs\CashGapForecastFilters;
use App\BusinessModules\Features\Budgeting\DTOs\CashGapForecastItem;
use App\BusinessModules\Features\Budgeting\Models\BudgetAmount;
use App\BusinessModules\Features\Budgeting\Models\BudgetArticle;
use App\BusinessModules\Features\Budgeting\Models\BudgetLimitReservation;
use App\BusinessModules\Features\Budgeting\Models\BudgetLine;
use App\BusinessModules\Features\Budgeting\Models\BudgetVersion;
use App\BusinessModules\Features\Budgeting\Services\BudgetWorkflowService;
use App\BusinessModules\Features\Budgeting\Services\CashGapForecastService;
use DateTimeImmutable;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\TestCase;

final class PaymentCalendarSourceServiceTest extends TestCase
{
    private PaymentCalendarSourceService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container;
        $loader = new FileLoader(new Filesystem, dirname(__DIR__, 5).DIRECTORY_SEPARATOR.'lang');
        $translator = new Translator($loader, 'ru');

        $container->instance('translator', $translator);
        $container->instance('config', new Repository([
            'app' => [
                'locale' => 'ru',
                'fallback_locale' => 'ru',
            ],
        ]));
        $container->instance('app', new class
        {
            public function getLocale(): string
            {
                return 'ru';
            }
        });

        Facade::setFacadeApplication($container);

        $this->service = new PaymentCalendarSourceService;
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function test_payment_document_maps_to_calendar_item(): void
    {
        $item = $this->service->fromPaymentDocument($this->paymentDocument(), $this->date('2026-01-01'));

        $this->assertInstanceOf(PaymentCalendarItem::class, $item);
        $this->assertSame(42, $item->organizationId);
        $this->assertSame('2026-01-10', $item->date);
        $this->assertSame(PaymentCalendarItem::DIRECTION_OUTFLOW, $item->direction);
        $this->assertSame(PaymentCalendarItem::BUCKET_APPROVED, $item->bucket);
        $this->assertSame('1000.00', $item->amount);
        $this->assertSame('1000.00', $item->remainingAmount);
        $this->assertSame('RUB', $item->currency);
        $this->assertSame('payment-document:118', $item->cashFlowKey);
        $this->assertSame('payment_document', $item->sourceType);
        $this->assertSame(118, $item->sourceId);
    }

    public function test_payment_schedule_date_has_priority_over_document_date(): void
    {
        $document = $this->paymentDocument([
            'id' => 120,
            'due_date' => '2026-01-20',
            'scheduled_at' => null,
        ]);
        $schedule = $this->paymentSchedule([
            'id' => 501,
            'payment_document_id' => 120,
            'due_date' => '2026-01-05',
        ]);
        $schedule->setRelation('paymentDocument', $document);

        $item = $this->service->fromPaymentSchedule($schedule, $this->date('2026-01-01'));

        $this->assertInstanceOf(PaymentCalendarItem::class, $item);
        $this->assertSame('2026-01-05', $item->date);
        $this->assertSame('2026-01-20', $item->originalDate);
        $this->assertSame(PaymentCalendarItem::BUCKET_SCHEDULED, $item->bucket);
        $this->assertSame('payment-document:120:payment-schedule:501', $item->cashFlowKey);
    }

    public function test_partially_paid_payment_uses_remaining_amount(): void
    {
        $item = $this->service->fromPaymentDocument($this->paymentDocument([
            'status' => PaymentDocumentStatus::PARTIALLY_PAID->value,
            'amount' => 1_000,
            'paid_amount' => 250,
            'remaining_amount' => 750,
        ]), $this->date('2026-01-01'));

        $this->assertInstanceOf(PaymentCalendarItem::class, $item);
        $this->assertSame('1000.00', $item->amount);
        $this->assertSame('750.00', $item->remainingAmount);
    }

    public function test_reservation_and_payment_with_same_cash_flow_key_are_not_counted_twice(): void
    {
        $document = $this->paymentDocument([
            'id' => 100,
            'amount' => 400,
            'remaining_amount' => 400,
        ]);
        $reservation = $this->budgetLimitReservation([
            'id' => 900,
            'payment_document_id' => 100,
            'amount' => 400,
        ]);
        $reservation->setRelation('paymentDocument', $document);

        $paymentItem = $this->service->fromPaymentDocument($document, $this->date('2026-01-01'));
        $reservationItem = $this->service->fromBudgetLimitReservation($reservation, $this->date('2026-01-01'));

        $this->assertInstanceOf(PaymentCalendarItem::class, $paymentItem);
        $this->assertInstanceOf(PaymentCalendarItem::class, $reservationItem);
        $this->assertSame($paymentItem->cashFlowKey, $reservationItem->cashFlowKey);

        $forecast = (new CashGapForecastService)->forecast(
            new CashGapForecastContext(
                periodStart: '2026-01-10',
                periodEnd: '2026-01-10',
                openingBalance: 1_000.0,
                filters: new CashGapForecastFilters(organizationId: 42),
            ),
            [
                $reservationItem->toCashGapForecastItem(),
                $paymentItem->toCashGapForecastItem(),
            ],
        )->toArray();

        $this->assertSame('600.00', $forecast['closing_balance']);
        $this->assertSame(1, $forecast['meta']['included_items']);
        $this->assertSame(1, $forecast['meta']['excluded_items']);
    }

    public function test_normalize_items_keeps_higher_priority_for_same_cash_flow_key(): void
    {
        $document = $this->paymentDocument([
            'id' => 100,
            'amount' => 400,
            'remaining_amount' => 400,
        ]);
        $reservation = $this->budgetLimitReservation([
            'id' => 900,
            'payment_document_id' => 100,
            'amount' => 400,
        ]);
        $reservation->setRelation('paymentDocument', $document);

        $paymentItem = $this->service->fromPaymentDocument($document, $this->date('2026-01-01'));
        $reservationItem = $this->service->fromBudgetLimitReservation($reservation, $this->date('2026-01-01'));

        $items = $this->service->normalizeItems([
            $reservationItem,
            $paymentItem,
        ], new PaymentCalendarSourceFilters(
            organizationId: 42,
            periodStart: '2026-01-01',
            periodEnd: '2026-01-31',
        ));

        $this->assertCount(1, $items);
        $this->assertSame('payment_document', $items[0]->sourceType);
        $this->assertSame('payment-document:100', $items[0]->cashFlowKey);
    }

    public function test_foreign_organization_is_excluded(): void
    {
        $filters = new PaymentCalendarSourceFilters(
            organizationId: 42,
            periodStart: '2026-01-01',
            periodEnd: '2026-01-31',
        );

        $items = $this->service->normalizeItems([
            $this->service->fromPaymentDocument($this->paymentDocument(), $this->date('2026-01-01')),
            $this->service->fromPaymentDocument($this->paymentDocument([
                'id' => 119,
                'organization_id' => 7,
            ]), $this->date('2026-01-01')),
        ], $filters);

        $this->assertCount(1, $items);
        $this->assertSame(42, $items[0]->organizationId);
    }

    public function test_filters_support_direction_bucket_and_source_type(): void
    {
        $outflowDocument = $this->service->fromPaymentDocument($this->paymentDocument(), $this->date('2026-01-01'));
        $transaction = $this->paymentTransaction([
            'id' => 302,
            'payer_organization_id' => 7,
            'payee_organization_id' => 42,
        ]);
        $inflowFact = $this->service->fromPaymentTransaction($transaction);

        $items = $this->service->normalizeItems([
            $outflowDocument,
            $inflowFact,
        ], new PaymentCalendarSourceFilters(
            organizationId: 42,
            periodStart: '2026-01-01',
            periodEnd: '2026-01-31',
            direction: PaymentCalendarItem::DIRECTION_INFLOW,
            bucket: PaymentCalendarItem::BUCKET_FACT,
            sourceType: 'payment_transaction',
        ));

        $this->assertCount(1, $items);
        $this->assertSame(PaymentCalendarItem::DIRECTION_INFLOW, $items[0]->direction);
        $this->assertSame(PaymentCalendarItem::BUCKET_FACT, $items[0]->bucket);
        $this->assertSame('payment_transaction', $items[0]->sourceType);
    }

    public function test_overdue_item_gets_overdue_bucket(): void
    {
        $item = $this->service->fromPaymentDocument($this->paymentDocument([
            'due_date' => '2026-01-01',
            'scheduled_at' => null,
        ]), $this->date('2026-01-05'));

        $this->assertInstanceOf(PaymentCalendarItem::class, $item);
        $this->assertSame(PaymentCalendarItem::BUCKET_OVERDUE, $item->bucket);
    }

    public function test_payment_transaction_preserves_original_transaction_date(): void
    {
        $item = $this->service->fromPaymentTransaction($this->paymentTransaction([
            'transaction_date' => '2026-01-09',
            'value_date' => '2026-01-10',
        ]));

        $this->assertInstanceOf(PaymentCalendarItem::class, $item);
        $this->assertSame('2026-01-10', $item->date);
        $this->assertSame('2026-01-09', $item->originalDate);
    }

    public function test_calendar_item_maps_to_cash_gap_forecast_item(): void
    {
        $item = new PaymentCalendarItem(
            organizationId: 42,
            date: '2026-01-10',
            originalDate: '2026-01-05',
            direction: PaymentCalendarItem::DIRECTION_OUTFLOW,
            bucket: PaymentCalendarItem::BUCKET_SCHEDULED,
            amount: 1_000.0,
            remainingAmount: 700.0,
            currency: 'RUB',
            probability: '1',
            status: 'scheduled',
            sourceType: 'payment_schedule',
            sourceId: 501,
            cashFlowKey: 'payment-document:100:payment-schedule:501',
            projectId: 14,
            counterpartyId: 30,
            budgetArticleId: 77,
            responsibilityCenterId: 88,
        );

        $cashGapItem = $item->toCashGapForecastItem();

        $this->assertSame(CashGapForecastItem::BUCKET_SCHEDULED_OUTFLOW, $cashGapItem->bucket);
        $this->assertSame('700.00', $cashGapItem->amount);
        $this->assertSame('2026-01-05', $cashGapItem->originalDate);
        $this->assertSame(42, $cashGapItem->organizationId);
        $this->assertSame('77', $cashGapItem->budgetArticleId);
        $this->assertSame('88', $cashGapItem->responsibilityCenterId);
        $this->assertSame('payment-document:100:payment-schedule:501', $cashGapItem->cashFlowKey);
    }

    public function test_bdds_budget_amount_maps_to_budget_plan_item(): void
    {
        $version = $this->budgetVersion();
        $article = $this->budgetArticle(['flow_direction' => 'inflow']);
        $line = $this->budgetLine();
        $line->setRelation('version', $version);
        $line->setRelation('article', $article);

        $amount = $this->budgetAmount([
            'forecast_amount' => 1_200,
            'plan_amount' => 1_000,
        ]);
        $amount->setRelation('line', $line);

        $item = $this->service->fromBudgetAmount($amount);

        $this->assertInstanceOf(PaymentCalendarItem::class, $item);
        $this->assertSame(PaymentCalendarItem::BUCKET_BUDGET_PLAN, $item->bucket);
        $this->assertSame(PaymentCalendarItem::DIRECTION_INFLOW, $item->direction);
        $this->assertSame('1200.00', $item->remainingAmount);
        $this->assertSame('budget-plan:701:2026-01-01:RUB', $item->cashFlowKey);
    }

    public function test_monthly_budget_plan_matches_period_inside_month(): void
    {
        $version = $this->budgetVersion();
        $article = $this->budgetArticle(['flow_direction' => 'inflow']);
        $line = $this->budgetLine();
        $line->setRelation('version', $version);
        $line->setRelation('article', $article);

        $amount = $this->budgetAmount(['month' => '2026-01-01']);
        $amount->setRelation('line', $line);

        $item = $this->service->fromBudgetAmount($amount);

        $items = $this->service->normalizeItems([
            $item,
        ], new PaymentCalendarSourceFilters(
            organizationId: 42,
            periodStart: '2026-01-15',
            periodEnd: '2026-01-31',
        ));

        $this->assertCount(1, $items);
        $this->assertSame(PaymentCalendarItem::BUCKET_BUDGET_PLAN, $items[0]->bucket);
    }

    public function test_monthly_reservation_without_document_matches_period_inside_month(): void
    {
        $item = $this->service->fromBudgetLimitReservation($this->budgetLimitReservation([
            'payment_document_id' => null,
            'period_month' => '2026-01-01',
        ]), $this->date('2025-12-20'));

        $items = $this->service->normalizeItems([
            $item,
        ], new PaymentCalendarSourceFilters(
            organizationId: 42,
            periodStart: '2026-01-15',
            periodEnd: '2026-01-31',
        ));

        $this->assertCount(1, $items);
        $this->assertSame(PaymentCalendarItem::BUCKET_RESERVED, $items[0]->bucket);
    }

    public function test_managed_document_and_reservation_do_not_duplicate_installments_or_invent_due_date(): void
    {
        $document = $this->paymentDocument([
            'schedule_source' => 'procurement',
            'due_date' => null,
            'scheduled_at' => null,
        ]);
        $reservation = $this->budgetLimitReservation();
        $reservation->setRelation('paymentDocument', $document);

        $this->assertNull($this->service->fromPaymentDocument($document, $this->date('2026-01-20')));
        $this->assertNull($this->service->fromBudgetLimitReservation($reservation, $this->date('2026-01-20')));
    }

    public function test_managed_installment_uses_its_own_due_date_and_cannot_be_dragged_to_another_day(): void
    {
        $document = $this->paymentDocument([
            'schedule_source' => 'procurement',
        ]);
        $schedule = $this->paymentSchedule([
            'source_key' => 'procurement:receipt:27',
            'due_date' => '2026-01-25',
            'amount' => 600,
            'paid_amount' => 100,
        ]);
        $schedule->setRelation('paymentDocument', $document);

        $item = $this->service->fromPaymentSchedule($schedule, $this->date('2026-01-20'));

        $this->assertInstanceOf(PaymentCalendarItem::class, $item);
        $this->assertSame('2026-01-25', $item->date);
        $this->assertSame('500.00', $item->remainingAmount);
        $this->assertSame(PaymentCalendarItem::BUCKET_SCHEDULED, $item->bucket);
        $this->assertFalse($item->editable);
    }

    public function test_cancelled_document_does_not_leave_payable_installments_in_calendar(): void
    {
        $schedule = $this->paymentSchedule(['source_key' => 'procurement:receipt:27']);
        $schedule->setRelation('paymentDocument', $this->paymentDocument([
            'status' => PaymentDocumentStatus::CANCELLED->value,
            'schedule_source' => 'procurement',
        ]));

        $this->assertNull($this->service->fromPaymentSchedule($schedule, $this->date('2026-01-20')));
    }

    public function test_metadata_cannot_hide_an_ordinary_payment_document(): void
    {
        $document = $this->paymentDocument([
            'metadata' => json_encode(['payment_schedule_source' => 'procurement']),
        ]);

        $this->assertInstanceOf(PaymentCalendarItem::class, $this->service->fromPaymentDocument($document));
    }

    public function test_undated_installment_keeps_unpaid_amount_without_inventing_a_calendar_day(): void
    {
        $schedule = $this->paymentSchedule([
            'due_date' => null,
            'source_key' => 'procurement:unreceived',
            'amount' => '600.01',
            'paid_amount' => '100.00',
        ]);
        $schedule->setRelation('paymentDocument', $this->paymentDocument(['schedule_source' => 'procurement']));

        $item = $this->service->fromUndatedPaymentSchedule($schedule);

        $this->assertNotNull($item);
        $this->assertSame('500.01', $item->remainingAmount);
        $this->assertNull($item->toArray()['date']);
        $this->assertSame('undated', $item->bucket);
        $this->assertFalse($item->toArray()['editable']);
        $this->assertSame(118, $item->toArray()['drill_down']['payment_document_id']);
        $this->assertNull($this->service->fromPaymentSchedule($schedule));

        $base = ['organizationId' => 42, 'periodStart' => '2030-01-01', 'periodEnd' => '2030-01-31'];
        $this->assertTrue((new PaymentCalendarSourceFilters(...$base))->matches($item));
        foreach ([
            ['organizationId' => 7], ['projectId' => 99], ['counterpartyId' => 99],
            ['budgetArticleId' => 99], ['responsibilityCenterId' => 99], ['currency' => 'USD'],
            ['direction' => 'inflow'], ['bucket' => 'overdue'], ['sourceType' => 'payment_transaction'],
        ] as $filter) {
            $this->assertFalse((new PaymentCalendarSourceFilters(...array_replace($base, $filter)))->matches($item));
        }
    }

    public function test_new_installment_with_null_paid_amount_is_treated_as_unpaid(): void
    {
        $schedule = $this->paymentSchedule(['amount' => '2100.00', 'paid_amount' => null]);
        $schedule->setRelation('paymentDocument', $this->paymentDocument());

        $dated = $this->service->fromPaymentSchedule($schedule);
        $this->assertNotNull($dated);
        $this->assertSame('2100.00', $dated->remainingAmount);

        $schedule->due_date = null;
        $undated = $this->service->fromUndatedPaymentSchedule($schedule);
        $this->assertNotNull($undated);
        $this->assertSame('2100.00', $undated->remainingAmount);
    }

    public function test_undated_projection_excludes_dated_paid_cancelled_and_zero_installments(): void
    {
        foreach ([
            ['due_date' => '2026-01-10'], ['status' => 'paid'],
            ['amount' => 0], ['amount' => 100, 'paid_amount' => 100],
        ] as $attributes) {
            $schedule = $this->paymentSchedule(array_replace(['due_date' => null], $attributes));
            $schedule->setRelation('paymentDocument', $this->paymentDocument(['schedule_source' => 'procurement']));
            $this->assertNull($this->service->fromUndatedPaymentSchedule($schedule));
        }
        $schedule = $this->paymentSchedule(['due_date' => null]);
        $schedule->setRelation('paymentDocument', $this->paymentDocument(['status' => PaymentDocumentStatus::CANCELLED->value]));
        $this->assertNull($this->service->fromUndatedPaymentSchedule($schedule));
    }

    public function test_undated_summary_keeps_currencies_separate_and_deduplicates_rows(): void
    {
        $items = [];
        foreach ([['RUB', 42], ['USD', 42], ['RUB', 7]] as $index => [$currency, $organizationId]) {
            $schedule = $this->paymentSchedule([
                'id' => 501 + $index, 'due_date' => null, 'amount' => '100.01', 'paid_amount' => '0.02',
            ]);
            $schedule->setRelation('paymentDocument', $this->paymentDocument([
                'currency' => $currency, 'organization_id' => $organizationId,
            ]));
            $items[] = $this->service->fromUndatedPaymentSchedule($schedule);
        }
        $items[] = $items[0];
        $summary = $this->service->summarizeUndated($items, new PaymentCalendarSourceFilters(
            organizationId: 42, periodStart: '2030-01-01', periodEnd: '2030-01-31',
        ));

        $this->assertSame(2, $summary['items_count']);
        $this->assertCount(2, $summary['items']);
        $this->assertSame([
            'RUB' => ['inflow' => '0.00', 'outflow' => '99.99'],
            'USD' => ['inflow' => '0.00', 'outflow' => '99.99'],
        ], $summary['totals_by_currency']);
    }

    private function paymentDocument(array $attributes = []): PaymentDocument
    {
        $document = new PaymentDocument;
        $document->setRawAttributes(array_merge([
            'id' => 118,
            'organization_id' => 42,
            'project_id' => 14,
            'budget_article_id' => 77,
            'responsibility_center_id' => 88,
            'document_number' => 'PAY-118',
            'document_date' => '2026-01-02',
            'direction' => InvoiceDirection::OUTGOING->value,
            'amount' => 1_000,
            'paid_amount' => 0,
            'remaining_amount' => 1_000,
            'currency' => 'RUB',
            'status' => PaymentDocumentStatus::APPROVED->value,
            'due_date' => '2026-01-10',
            'scheduled_at' => null,
            'contractor_id' => 30,
        ], $attributes), true);

        return $document;
    }

    private function paymentSchedule(array $attributes = []): PaymentSchedule
    {
        $schedule = new PaymentSchedule;
        $schedule->setRawAttributes(array_merge([
            'id' => 501,
            'payment_document_id' => 118,
            'installment_number' => 1,
            'due_date' => '2026-01-10',
            'amount' => 1_000,
            'paid_amount' => 0,
            'status' => 'pending',
        ], $attributes), true);

        return $schedule;
    }

    private function paymentTransaction(array $attributes = []): PaymentTransaction
    {
        $transaction = new PaymentTransaction;
        $transaction->setRawAttributes(array_merge([
            'id' => 301,
            'organization_id' => 42,
            'project_id' => 14,
            'payer_organization_id' => 42,
            'payee_organization_id' => 7,
            'amount' => 500,
            'currency' => 'RUB',
            'transaction_date' => '2026-01-10',
            'value_date' => '2026-01-10',
            'status' => PaymentTransactionStatus::COMPLETED->value,
            'reference_number' => 'TRX-301',
        ], $attributes), true);

        return $transaction;
    }

    private function budgetLimitReservation(array $attributes = []): BudgetLimitReservation
    {
        $reservation = new BudgetLimitReservation;
        $reservation->setRawAttributes(array_merge([
            'id' => 900,
            'organization_id' => 42,
            'payment_document_id' => 118,
            'budget_article_id' => 77,
            'responsibility_center_id' => 88,
            'project_id' => 14,
            'counterparty_id' => 30,
            'period_month' => '2026-01-01',
            'currency' => 'RUB',
            'amount' => 1_000,
            'status' => BudgetLimitReservation::STATUS_RESERVED,
        ], $attributes), true);

        return $reservation;
    }

    private function budgetVersion(array $attributes = []): BudgetVersion
    {
        $version = new BudgetVersion;
        $version->setRawAttributes(array_merge([
            'id' => 601,
            'organization_id' => 42,
            'budget_kind' => 'bdds',
            'status' => BudgetWorkflowService::STATUS_ACTIVE,
        ], $attributes), true);

        return $version;
    }

    private function budgetArticle(array $attributes = []): BudgetArticle
    {
        $article = new BudgetArticle;
        $article->setRawAttributes(array_merge([
            'id' => 77,
            'organization_id' => 42,
            'budget_kind' => 'bdds',
            'flow_direction' => 'outflow',
        ], $attributes), true);

        return $article;
    }

    private function budgetLine(array $attributes = []): BudgetLine
    {
        $line = new BudgetLine;
        $line->setRawAttributes(array_merge([
            'id' => 701,
            'budget_version_id' => 601,
            'budget_article_id' => 77,
            'responsibility_center_id' => 88,
            'project_id' => 14,
            'counterparty_id' => 30,
            'currency' => 'RUB',
        ], $attributes), true);

        return $line;
    }

    private function budgetAmount(array $attributes = []): BudgetAmount
    {
        $amount = new BudgetAmount;
        $amount->setRawAttributes(array_merge([
            'id' => 801,
            'budget_line_id' => 701,
            'month' => '2026-01-01',
            'plan_amount' => 1_000,
            'forecast_amount' => 1_000,
            'currency' => 'RUB',
        ], $attributes), true);

        return $amount;
    }

    private function date(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date);
    }
}
