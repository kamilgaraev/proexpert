<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessModules\Core\Payments;

use App\BusinessModules\Core\Payments\DTOs\PaymentCalendarSourceFilters;
use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Models\PaymentSchedule;
use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use App\BusinessModules\Core\Payments\Services\PaymentCalendarSourceService;
use App\BusinessModules\Features\Budgeting\Models\BudgetArticle;
use App\BusinessModules\Features\Budgeting\Models\BudgetLimitReservation;
use App\BusinessModules\Features\Budgeting\Models\ResponsibilityCenter;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\Support\PortfolioDecimal;
use App\Models\Organization;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class PaymentCalendarRequestOrderNettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_order_replaces_its_overdue_request_in_forecast(): void
    {
        [$request, $order, $filters] = $this->fixture();
        $items = $this->collect($filters);

        $this->assertSame('100000.00', $this->total($items));
        $this->assertSame([$order->id], array_column($items, 'sourceId'));
        $this->assertSame('100000.00', $request->fresh()->remaining_amount);
        $this->assertSame('100000.00', $order->fresh()->remaining_amount);
    }

    public function test_reserved_draft_order_replaces_request_without_duplicating_its_calendar_reserve(): void
    {
        [$request, $order, $filters] = $this->fixture([
            'status' => PaymentDocumentStatus::DRAFT,
            'amount' => '1000.00', 'remaining_amount' => '1000.00', 'due_date' => '2026-10-22',
        ]);
        $request->update([
            'amount' => '1000.00', 'remaining_amount' => '1000.00',
            'document_date' => '2026-10-16', 'due_date' => '2026-10-22',
        ]);
        $reservation = $this->reservation($order, ['amount' => '1000.00']);
        foreach ([$request, $order] as $document) {
            $document->update([
                'budget_article_id' => $reservation->budget_article_id,
                'responsibility_center_id' => $reservation->responsibility_center_id,
            ]);
        }
        $invoice = $request->replicate();
        $invoice->fill([
            'document_type' => PaymentDocumentType::INVOICE, 'status' => PaymentDocumentStatus::SCHEDULED,
            'document_number' => 'УЧ-СЧ-'.uniqid(), 'amount' => '100000.00',
            'remaining_amount' => '100000.00', 'due_date' => '2026-10-20',
        ])->save();

        $items = $this->collect($filters);

        $this->assertSame('101000.00', $this->total($items));
        $this->assertCount(2, $items);
        $this->assertNotContains($request->id, collect($items)->where('sourceType', 'payment_document')->pluck('sourceId')->all());
        $this->assertContains($invoice->id, collect($items)->where('sourceType', 'payment_document')->pluck('sourceId')->all());
        $reservedItem = collect($items)->firstWhere('sourceType', 'budget_limit_reservation');
        $this->assertSame($reservation->id, $reservedItem?->sourceId);
        $this->assertSame($order->id, $reservedItem?->drillDown['payment_document_id']);
        $this->assertSame('1000.00', $reservedItem?->remainingAmount);
        $this->assertSame('0.00', $order->fresh()->paid_amount);
        $this->assertSame(PaymentDocumentStatus::DRAFT, $order->fresh()->status);
        $order->update(['status' => PaymentDocumentStatus::APPROVED]);
        $approvedItems = $this->collect($filters);
        $this->assertSame('101000.00', $this->total($approvedItems));
        $this->assertCount(2, $approvedItems);
    }

    public static function invalidDraftReservations(): array
    {
        return [
            'different-amount' => [['amount' => '50000.00']],
            'different-currency' => [['currency' => 'USD']],
            'different-month' => [['period_month' => '2026-09-01']],
            'released' => [['status' => BudgetLimitReservation::STATUS_RELEASED]],
        ];
    }

    #[DataProvider('invalidDraftReservations')]
    public function test_draft_with_incompatible_reservation_does_not_cover_request(array $attributes): void
    {
        [$request, $order, $filters] = $this->fixture(['status' => PaymentDocumentStatus::DRAFT]);
        $request->update(['due_date' => '2026-10-20']);
        $reservation = $this->reservation($order, $attributes);
        foreach ([$request, $order] as $document) {
            $document->update([
                'budget_article_id' => $reservation->budget_article_id,
                'responsibility_center_id' => $reservation->responsibility_center_id,
            ]);
        }

        $item = collect($this->collect($filters))->first(
            fn ($item): bool => $item->sourceType === 'payment_document' && $item->sourceId === $request->id,
        );

        $this->assertSame('100000.00', $item?->remainingAmount);
        $this->assertSame(PaymentDocumentStatus::DRAFT, $order->fresh()->status);
    }

    public static function orderStates(): array
    {
        return [
            'partial-approved' => ['approved', '40000.00', '0.00', '60000.00', '100000.00'],
            'partial-paid' => ['partially_paid', '40000.00', '10000.00', '60000.00', '100000.00'],
            'paid' => ['paid', '100000.00', '100000.00', '0.00', '100000.00'],
            'draft' => ['draft', '100000.00', '0.00', '100000.00', '100000.00'],
            'cancelled' => ['cancelled', '100000.00', '0.00', '100000.00', '100000.00'],
            'rejected' => ['rejected', '100000.00', '0.00', '100000.00', '100000.00'],
        ];
    }

    #[DataProvider('orderStates')]
    public function test_order_state_and_full_amount_determine_request_remainder(
        string $status, string $amount, string $paid, string $expectedRequest, string $expectedTotal,
    ): void {
        [$request, $order, $filters] = $this->fixture([
            'status' => $status, 'amount' => $amount, 'paid_amount' => $paid,
            'remaining_amount' => PortfolioDecimal::subtract($amount, $paid),
        ]);
        if ($paid !== '0.00') {
            PaymentTransaction::query()->create([
                'payment_document_id' => $order->id, 'organization_id' => $order->organization_id,
                'project_id' => $order->project_id, 'payer_organization_id' => $order->payer_organization_id,
                'payee_organization_id' => $order->payee_organization_id, 'amount' => $paid, 'currency' => 'RUB',
                'payment_method' => 'bank_transfer', 'transaction_date' => '2026-10-15', 'value_date' => '2026-10-15',
                'status' => 'completed', 'created_by_user_id' => $order->created_by_user_id,
            ]);
        }

        $items = $this->collect($filters);
        $requestItem = collect($items)->first(fn ($item): bool => $item->sourceType === 'payment_document' && $item->sourceId === $request->id);
        $this->assertSame($expectedRequest, $requestItem?->remainingAmount ?? '0.00');
        $this->assertSame($expectedTotal, $this->total($items));
        $this->assertSame('100000.00', $request->fresh()->remaining_amount);
        $this->assertSame($paid, $order->fresh()->paid_amount);
    }

    public function test_future_order_covers_request_even_outside_the_selected_period(): void
    {
        [$request, $order, $filters] = $this->fixture(['due_date' => '2026-11-20']);

        $this->assertSame([], $this->collect($filters));
        $november = new PaymentCalendarSourceFilters(
            organizationId: $filters->organizationId, periodStart: '2026-11-01', periodEnd: '2026-11-30',
            projectId: $filters->projectId,
        );
        $items = $this->collect($november);
        $this->assertSame([$order->id], array_column($items, 'sourceId'));
        $this->assertSame('100000.00', $this->total($items));
        $this->assertSame('100000.00', $request->fresh()->remaining_amount);
    }

    public function test_hidden_order_does_not_reduce_visible_request(): void
    {
        [$request, $order, $filters] = $this->fixture();
        $items = app(PaymentCalendarSourceService::class)->collect(
            $filters, CarbonImmutable::parse('2026-10-15'),
            fn ($query) => $query->where('id', '!=', $order->id),
        );

        $this->assertSame([$request->id], array_column($items, 'sourceId'));
        $this->assertSame('100000.00', $this->total($items));
    }

    public static function unrelatedOrders(): array
    {
        return [
            'different-organization' => ['organization'],
            'different-project' => ['project'],
            'different-currency' => ['currency'],
            'different-direction' => ['direction'],
            'different-document-type' => ['type'],
            'different-source-type' => ['source'],
        ];
    }

    #[DataProvider('unrelatedOrders')]
    public function test_only_matching_typed_order_can_cover_a_request(string $case): void
    {
        [$request, $order, $filters] = $this->fixture();
        $changes = match ($case) {
            'organization' => ['organization_id' => Organization::factory()->verified()->create()->id],
            'project' => ['project_id' => Project::factory()->create(['organization_id' => $request->organization_id])->id],
            'currency' => ['currency' => 'USD'],
            'direction' => ['direction' => InvoiceDirection::INCOMING],
            'type' => ['document_type' => PaymentDocumentType::INVOICE],
            'source' => ['source_type' => 'unrelated_document'],
        };
        $order->update($changes);

        $items = $this->collect($filters);
        $requestItem = collect($items)->first(fn ($item): bool => $item->sourceType === 'payment_document' && $item->sourceId === $request->id);
        $this->assertSame('100000.00', $requestItem?->remainingAmount);
        $this->assertSame(in_array($case, ['type', 'source'], true) ? '200000.00' : '100000.00', $this->total($items));
    }

    public function test_multiple_orders_do_not_create_a_negative_request_or_hide_order_overcommitment(): void
    {
        [$request, $order, $filters] = $this->fixture(['amount' => '70000.00', 'remaining_amount' => '70000.00']);
        $other = $order->replicate();
        $other->fill(['document_number' => 'УЧ-ДОП-'.uniqid(), 'amount' => '60000.00', 'remaining_amount' => '60000.00'])->save();

        $items = $this->collect($filters);
        $this->assertSame('130000.00', $this->total($items));
        $this->assertEqualsCanonicalizing([$order->id, $other->id], array_column($items, 'sourceId'));
        $this->assertSame('100000.00', $request->fresh()->remaining_amount);
    }

    public function test_invoice_source_does_not_turn_independent_documents_into_request_netting(): void
    {
        [$request, , $filters] = $this->fixture();
        $request->update(['document_type' => PaymentDocumentType::INVOICE]);

        $this->assertSame('200000.00', $this->total($this->collect($filters)));
    }

    public static function reservedRequests(): array
    {
        return [['100000.00', '0.00'], ['40000.00', '60000.00']];
    }

    #[DataProvider('reservedRequests')]
    public function test_reservation_cannot_restore_the_delegated_request_amount(string $amount, string $expectedReservation): void
    {
        [$request, , $filters] = $this->fixture(['amount' => $amount, 'remaining_amount' => $amount]);
        $reservation = $this->reservation($request);

        $this->assertSame('100000.00', $this->total($this->collect($filters)));
        $reservedOnly = $this->collect(new PaymentCalendarSourceFilters(
            organizationId: $filters->organizationId, periodStart: $filters->periodStart, periodEnd: $filters->periodEnd,
            projectId: $filters->projectId, sourceType: 'budget_limit_reservation',
        ));
        $this->assertSame($expectedReservation, $this->total($reservedOnly));
        $this->assertSame('100000.00', $reservation->fresh()->amount);
        $this->assertSame(BudgetLimitReservation::STATUS_RESERVED, $reservation->fresh()->status);
    }

    public static function reservationDimensions(): array
    {
        return [['currency'], ['project'], ['direction']];
    }

    #[DataProvider('reservationDimensions')]
    public function test_reservation_dimensions_are_preserved(string $case): void
    {
        [$request, $order, $filters] = $this->fixture(['amount' => '40000.00', 'remaining_amount' => '40000.00']);
        $changes = match ($case) {
            'currency' => ['currency' => 'USD'],
            'project' => ['project_id' => Project::factory()->create(['organization_id' => $request->organization_id])->id],
            'direction' => [],
        };
        if ($case === 'direction') {
            $request->update(['direction' => InvoiceDirection::INCOMING]);
            $order->update(['direction' => InvoiceDirection::INCOMING]);
        }
        $reservation = $this->reservation($request, $changes);
        $items = $this->collect(new PaymentCalendarSourceFilters(
            organizationId: $filters->organizationId, periodStart: $filters->periodStart, periodEnd: $filters->periodEnd,
            sourceType: 'budget_limit_reservation',
        ));

        $this->assertSame('100000.00', $this->total($items));
        $this->assertSame($reservation->currency, $items[0]->currency);
        $this->assertSame($reservation->project_id, $items[0]->projectId);
        $this->assertSame('outflow', $items[0]->direction);
    }

    private function reservation(PaymentDocument $request, array $overrides = []): BudgetLimitReservation
    {
        $article = BudgetArticle::query()->create([
            'organization_id' => $request->organization_id, 'code' => 'УЧ-СТ-'.uniqid(), 'name' => 'Учебные работы',
            'budget_kind' => 'bdds', 'flow_direction' => 'outflow', 'is_leaf' => true, 'is_active' => true,
        ]);
        $center = ResponsibilityCenter::query()->create([
            'organization_id' => $request->organization_id, 'center_type' => 'project',
            'code' => 'УЧ-ЦФО-'.uniqid(), 'name' => 'Учебный проект', 'is_active' => true,
        ]);
        return BudgetLimitReservation::query()->create(array_replace([
            'organization_id' => $request->organization_id, 'payment_document_id' => $request->id,
            'project_id' => $request->project_id, 'budget_article_id' => $article->id,
            'responsibility_center_id' => $center->id, 'period_month' => '2026-10-01',
            'currency' => 'RUB', 'amount' => '100000.00', 'status' => BudgetLimitReservation::STATUS_RESERVED, 'reserved_at' => now(),
        ], $overrides));
    }

    public function test_coverage_is_allocated_once_across_all_dated_and_undated_installments(): void
    {
        [$request, $order, $filters] = $this->fixture(['amount' => '40000.00', 'remaining_amount' => '40000.00']);
        $schedules = [];
        foreach ([['2026-09-20', '30000.00'], ['2026-11-20', '30000.00'], [null, '40000.00']] as $index => [$date, $amount]) {
            $schedules[] = PaymentSchedule::query()->create([
                'payment_document_id' => $request->id, 'installment_number' => $index + 1,
                'due_date' => $date, 'amount' => $amount, 'paid_amount' => '0.00', 'status' => 'pending',
            ]);
        }
        $before = PaymentSchedule::query()->where('payment_document_id', $request->id)->orderBy('id')->get()->toArray();
        $service = app(PaymentCalendarSourceService::class);
        $this->assertSame([$order->id], array_column($this->collect($filters), 'sourceId'));
        $november = new PaymentCalendarSourceFilters(
            organizationId: $filters->organizationId, periodStart: '2026-11-01', periodEnd: '2026-11-30',
            projectId: $filters->projectId,
        );
        $dated = $this->collect($november);
        $undated = $service->collectUndated($filters);

        $this->assertSame([$schedules[1]->id], array_column($dated, 'sourceId'));
        $this->assertSame('20000.00', $this->total($dated));
        $this->assertSame([$schedules[2]->id], array_column($undated, 'sourceId'));
        $this->assertSame('40000.00', $this->total($undated));
        $this->assertSame('30000.00', $dated[0]->amount);
        $this->assertSame($before, PaymentSchedule::query()->where('payment_document_id', $request->id)->orderBy('id')->get()->toArray());
    }

    private function fixture(array $orderOverrides = []): array
    {
        Queue::fake();
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $payee = Organization::factory()->verified()->create();
        $shared = [
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'payer_organization_id' => $context->organization->id, 'payee_organization_id' => $payee->id,
            'direction' => InvoiceDirection::OUTGOING, 'status' => PaymentDocumentStatus::APPROVED,
            'amount' => '100000.00', 'paid_amount' => '0.00', 'remaining_amount' => '100000.00',
            'currency' => 'RUB', 'created_by_user_id' => $context->user->id,
        ];
        $request = PaymentDocument::query()->create($shared + [
            'document_type' => PaymentDocumentType::PAYMENT_REQUEST,
            'document_number' => 'УЧ-ЗАЯВКА-'.uniqid(), 'document_date' => '2026-09-01', 'due_date' => '2026-09-20',
        ]);
        $order = PaymentDocument::query()->create(array_replace($shared, [
            'document_type' => PaymentDocumentType::PAYMENT_ORDER,
            'document_number' => 'УЧ-ПОРУЧЕНИЕ-'.uniqid(), 'document_date' => '2026-10-01', 'due_date' => '2026-10-20',
            'source_type' => PaymentDocument::class, 'source_id' => $request->id,
        ], $orderOverrides));

        return [$request, $order, new PaymentCalendarSourceFilters(
            organizationId: $context->organization->id, periodStart: '2026-10-01', periodEnd: '2026-10-31',
            projectId: $project->id, currency: 'RUB', direction: 'outflow',
        )];
    }

    private function collect(PaymentCalendarSourceFilters $filters): array
    {
        return app(PaymentCalendarSourceService::class)->collect($filters, CarbonImmutable::parse('2026-10-15'));
    }

    private function total(array $items): string
    {
        $total = '0.00';
        foreach ($items as $item) {
            $total = PortfolioDecimal::add($total, $item->remainingAmount);
        }

        return $total;
    }
}
