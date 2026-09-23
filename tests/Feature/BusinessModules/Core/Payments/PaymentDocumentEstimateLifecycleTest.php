<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessModules\Core\Payments;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\InvoiceType;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Events\PaymentDocumentPaid;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use App\BusinessModules\Core\Payments\Services\PaymentDocumentPresenter;
use App\BusinessModules\Core\Payments\Services\PaymentDocumentService;
use App\BusinessModules\Core\Payments\Services\PaymentTransactionService;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\BasicWarehouse\Services\WarehouseReceiptFromPaymentService;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestStatusEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestTypeEnum;
use App\BusinessModules\Features\SiteRequests\Events\SiteRequestStatusChanged;
use App\BusinessModules\Features\SiteRequests\Listeners\CompleteSiteRequestsOnPaymentPaid;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\Enums\EstimatePositionItemType;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\ContractPerformanceAct;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Material;
use App\Models\MeasurementUnit;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PaymentDocumentEstimateLifecycleTest extends TestCase
{
    private PaymentDocumentService $service;

    private Organization $organization;

    private Organization $counterparty;

    private Project $project;

    private Estimate $estimate;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->service = app(PaymentDocumentService::class);
        $this->organization = Organization::factory()->create();
        $this->counterparty = Organization::factory()->create();
        $this->project = Project::factory()->create([
            'organization_id' => $this->organization->id,
        ]);
        $this->estimate = Estimate::query()->create([
            'organization_id' => $this->organization->id,
            'project_id' => $this->project->id,
            'number' => 'EST-001',
            'name' => 'Test estimate',
            'estimate_date' => now()->toDateString(),
        ]);
    }

    public function test_creating_payment_document_with_estimate_splits_does_not_mark_estimate_items_paid(): void
    {
        $item = $this->createEstimateItem([
            'quantity' => 10,
            'unit_price' => 100,
            'total_amount' => 1000,
        ]);

        $document = $this->createDocumentWithSplit($item, [
            'document_number' => 'PAY-EST-001',
            'status' => PaymentDocumentStatus::APPROVED->value,
        ]);

        $this->assertDatabaseHas('payment_document_estimate_splits', [
            'payment_document_id' => $document->id,
            'estimate_item_id' => $item->id,
            'amount' => 1000,
        ]);

        $item->refresh();

        $this->assertNull($item->actual_quantity);
        $this->assertNull($item->actual_unit_price);
        $this->assertSame('pending', $item->procurement_status);
    }

    public function test_estimate_item_paid_quantity_follows_registered_payment_amount(): void
    {
        $item = $this->createEstimateItem([
            'quantity' => 10,
            'unit_price' => 100,
            'total_amount' => 1000,
        ]);

        $document = $this->createDocumentWithSplit($item, [
            'document_number' => 'PAY-EST-002',
            'status' => PaymentDocumentStatus::SCHEDULED->value,
        ]);

        $this->service->registerPayment($document, 400, [
            'payment_method' => 'bank_transfer',
            'transaction_date' => now(),
        ]);

        $item->refresh();
        $document->refresh();

        $this->assertEquals(400.0, (float) $document->paid_amount);
        $this->assertEquals(600.0, (float) $document->remaining_amount);
        $this->assertSame(PaymentDocumentStatus::PARTIALLY_PAID, $document->status);
        $this->assertEquals(4.0, (float) $item->actual_quantity);
        $this->assertSame('ordered', $item->procurement_status);

        $this->service->registerPayment($document, 600, [
            'payment_method' => 'bank_transfer',
            'transaction_date' => now(),
        ]);

        $item->refresh();
        $document->refresh();

        $this->assertEquals(1000.0, (float) $document->paid_amount);
        $this->assertEquals(0.0, (float) $document->remaining_amount);
        $this->assertSame(PaymentDocumentStatus::PAID, $document->status);
        $this->assertEquals(10.0, (float) $item->actual_quantity);
        $this->assertEquals(100.0, (float) $item->actual_unit_price);
        $this->assertSame('paid', $item->procurement_status);
    }

    public function test_estimate_item_payment_progress_uses_decimal_arithmetic_for_large_partial_payment(): void
    {
        $item = $this->createEstimateItem([
            'quantity' => '300.00000000',
            'unit_price' => '33333333.3333',
            'total_amount' => '9999999999.99',
        ]);

        $document = $this->createDocumentWithSplit($item, [
            'document_number' => 'PAY-EST-DECIMAL',
            'status' => PaymentDocumentStatus::SCHEDULED->value,
            'amount' => '9999999999.99',
            'estimate_splits' => [[
                'estimate_item_id' => $item->id,
                'quantity' => '300.00000000',
                'unit_price_actual' => '33333333.3333',
                'amount' => '9999999999.99',
                'percentage' => 100,
            ]],
        ]);

        $this->service->registerPayment($document, '3333333333.33', [
            'payment_method' => 'bank_transfer',
            'transaction_date' => now(),
        ]);

        $item->refresh();

        $this->assertSame('100.00000000', (string) $item->actual_quantity);
        $this->assertSame('33333333.3333', (string) $item->actual_unit_price);
    }

    public function test_refund_reverses_estimate_item_payment_projection(): void
    {
        $actor = User::factory()->create(['current_organization_id' => $this->organization->id]);
        $item = $this->createEstimateItem(['quantity' => 10, 'unit_price' => 100, 'total_amount' => 1000]);
        $document = $this->createDocumentWithSplit($item, [
            'document_number' => 'PAY-EST-REFUND',
            'status' => PaymentDocumentStatus::SCHEDULED->value,
        ]);
        $this->service->registerPayment($document, '1000.00', [
            'payment_method' => 'bank_transfer',
            'transaction_date' => '2026-08-23',
            'idempotency_key' => 'payment-before-refund',
        ]);
        $transaction = PaymentTransaction::query()
            ->where('payment_document_id', $document->id)
            ->where('amount', '>', 0)
            ->sole();

        \Illuminate\Support\Facades\DB::enableQueryLog();
        \Illuminate\Support\Facades\DB::flushQueryLog();
        try {
            app(PaymentTransactionService::class)->refundPayment(
                transactionId: $transaction->id,
                organizationId: $this->organization->id,
                actorId: $actor->id,
                amount: '400.00',
                reason: 'Корректировка платежа',
                refundDate: '2026-08-24',
                idempotencyKey: 'refund-estimate-projection'
            );
            $lockingQueries = array_values(array_filter(\Illuminate\Support\Facades\DB::getQueryLog(),
                static fn (array $query): bool => str_contains(strtolower($query['query']), 'for update')));
        } finally {
            \Illuminate\Support\Facades\DB::disableQueryLog();
        }
        $this->assertGreaterThanOrEqual(2, count($lockingQueries));
        $this->assertStringContainsString('"payment_documents"', $lockingQueries[0]['query']);
        $this->assertStringContainsString('"payment_transactions"', $lockingQueries[1]['query']);

        $this->assertSame('600.00', $document->fresh()->paid_amount);
        $this->assertSame('6.00000000', $item->fresh()->actual_quantity);
        $this->assertSame('ordered', $item->fresh()->procurement_status);
    }

    public function test_partial_payment_does_not_dispatch_paid_lifecycle_event(): void
    {
        $item = $this->createEstimateItem([
            'quantity' => 10,
            'unit_price' => 100,
            'total_amount' => 1000,
        ]);

        $document = $this->createDocumentWithSplit($item, [
            'document_number' => 'PAY-EST-006',
            'status' => PaymentDocumentStatus::SCHEDULED->value,
        ]);

        Event::fake([PaymentDocumentPaid::class]);

        $this->service->registerPayment($document, 400, [
            'payment_method' => 'bank_transfer',
            'transaction_date' => now(),
        ]);

        Event::assertNotDispatched(PaymentDocumentPaid::class);

        $this->service->registerPayment($document->fresh(), 600, [
            'payment_method' => 'bank_transfer',
            'transaction_date' => now(),
        ]);

        Event::assertDispatched(
            PaymentDocumentPaid::class,
            fn (PaymentDocumentPaid $event): bool => $event->document->id === $document->id
                && abs($event->amount - 1000.0) < 0.001
                && $event->transactionId !== null
                && $event->recognizedAt !== null
                && $event->organizationId === (int) $document->organization_id
                && $event->projectId === (int) $document->project_id
                && $event->invoiceableType === $document->invoiceable_type
                && $event->invoiceableId === (int) $document->invoiceable_id
                && $event->currency === $document->currency
        );
    }

    public function test_repeated_payment_request_is_idempotent(): void
    {
        $item = $this->createEstimateItem([
            'quantity' => 10,
            'unit_price' => 100,
            'total_amount' => 1000,
        ]);
        $document = $this->createDocumentWithSplit($item, [
            'document_number' => 'PAY-IDEMPOTENT-001',
            'status' => PaymentDocumentStatus::SCHEDULED->value,
        ]);
        $paymentData = [
            'payment_method' => 'bank_transfer',
            'transaction_date' => now(),
            'idempotency_key' => 'payment-request-20260823-0001',
        ];

        $first = $this->service->registerPayment($document, 400, $paymentData);
        $second = $this->service->registerPayment($document->fresh(), 400, $paymentData);

        self::assertSame('400.00', $first->paid_amount);
        self::assertSame('400.00', $second->paid_amount);
        self::assertSame(1, PaymentTransaction::query()->where('payment_document_id', $document->id)->count());
    }

    public function test_reused_payment_idempotency_key_rejects_changed_payment_details(): void
    {
        foreach ([
            ['notes' => 'second note', 'metadata' => ['source' => 'field']],
            ['notes' => 'first note', 'metadata' => ['source' => 'admin']],
            ['notes' => 'first note', 'metadata' => ['source' => 'field'], 'reference_number' => 'CHANGED-REFERENCE'],
            ['notes' => 'first note', 'metadata' => ['source' => 'field'], 'bank_transaction_id' => 'CHANGED-BANK-EVENT'],
            ['notes' => 'first note', 'metadata' => ['source' => 'field'], 'budget_override_reason' => 'Другая причина'],
        ] as $index => $changedPayload) {
            $item = $this->createEstimateItem(['quantity' => 10, 'unit_price' => 100, 'total_amount' => 1000]);
            $document = $this->createDocumentWithSplit($item, [
                'document_number' => 'PAY-IDEMPOTENT-FINGERPRINT-'.($index + 1),
                'status' => PaymentDocumentStatus::SCHEDULED->value,
            ]);
            $original = [
                'payment_method' => 'bank_transfer',
                'transaction_date' => '2026-08-23',
                'value_date' => '2026-08-24',
                'idempotency_key' => 'payment-fingerprint-'.($index + 1),
                'notes' => 'first note',
                'metadata' => ['source' => 'field'],
                'bank_transaction_id' => 'BANK-FINGERPRINT-'.($index + 1),
            ];
            $this->service->registerPayment($document, 400, $original);

            try {
                $this->service->registerPayment($document->fresh(), 400, array_merge($original, $changedPayload));
                self::fail('Changed payment payload was accepted with a reused idempotency key');
            } catch (\DomainException $exception) {
                self::assertSame(trans_message('payments.validation.idempotency_conflict'), $exception->getMessage());
            }
        }
    }

    public function test_payment_retry_without_value_date_survives_a_change_of_day(): void
    {
        $item = $this->createEstimateItem(['quantity' => 10, 'unit_price' => 100, 'total_amount' => 1000]);
        $document = $this->createDocumentWithSplit($item, [
            'document_number' => 'PAY-IDEMPOTENT-NEXT-DAY',
            'status' => PaymentDocumentStatus::SCHEDULED->value,
        ]);
        $payload = [
            'payment_method' => 'bank_transfer',
            'transaction_date' => '2026-09-23',
            'idempotency_key' => 'payment-next-day-retry',
        ];

        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-09-23 23:59:00'));
        try {
            $this->service->registerPayment($document, '400.00', $payload);
            $this->travelTo(\Illuminate\Support\Carbon::parse('2026-09-24 00:01:00'));
            $replayed = $this->service->registerPayment($document->fresh(), '400.00', $payload);
        } finally {
            $this->travelBack();
        }

        self::assertSame('400.00', $replayed->paid_amount);
        self::assertSame(1, PaymentTransaction::query()->where('payment_document_id', $document->id)->count());
    }

    public function test_same_bank_event_cannot_be_applied_twice_with_different_idempotency_keys(): void
    {
        $item = $this->createEstimateItem(['quantity' => 10, 'unit_price' => 100, 'total_amount' => 1000]);
        $document = $this->createDocumentWithSplit($item, [
            'document_number' => 'PAY-BANK-EVENT-001',
            'status' => PaymentDocumentStatus::SCHEDULED->value,
        ]);
        $event = [
            'payment_method' => 'bank_transfer',
            'reference_number' => 'BANK-REF-001',
            'bank_transaction_id' => 'BANK-EVENT-001',
            'transaction_date' => '2026-08-23',
            'value_date' => '2026-08-24',
        ];

        $first = $this->service->registerPayment($document, '400.00', $event + ['idempotency_key' => 'request-a']);
        $second = $this->service->registerPayment($document->fresh(), '400.00', $event + ['idempotency_key' => 'request-b']);

        self::assertSame('400.00', $first->paid_amount);
        self::assertSame('400.00', $second->paid_amount);
        self::assertSame(1, PaymentTransaction::query()->where('bank_transaction_id', 'BANK-EVENT-001')->count());
    }

    public function test_reused_bank_event_with_changed_fingerprint_is_rejected(): void
    {
        $item = $this->createEstimateItem(['quantity' => 10, 'unit_price' => 100, 'total_amount' => 1000]);
        $document = $this->createDocumentWithSplit($item, [
            'document_number' => 'PAY-BANK-EVENT-002',
            'status' => PaymentDocumentStatus::SCHEDULED->value,
        ]);
        $this->service->registerPayment($document, '400.00', [
            'idempotency_key' => 'request-c',
            'payment_method' => 'bank_transfer',
            'reference_number' => 'BANK-REF-002',
            'bank_transaction_id' => 'BANK-EVENT-002',
            'transaction_date' => '2026-08-23',
            'value_date' => '2026-08-24',
        ]);

        $this->expectException(\DomainException::class);
        $this->service->registerPayment($document->fresh(), '400.00', [
            'idempotency_key' => 'request-d',
            'payment_method' => 'bank_transfer',
            'reference_number' => 'CHANGED-REFERENCE',
            'bank_transaction_id' => 'BANK-EVENT-002',
            'transaction_date' => '2026-08-23',
            'value_date' => '2026-08-24',
        ]);
    }

    public function test_invoice_from_act_requires_approval_and_is_idempotent(): void
    {
        $contractor = Contractor::query()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Act contractor',
            'source_organization_id' => $this->counterparty->id,
        ]);
        $contract = Contract::query()->create([
            'organization_id' => $this->organization->id,
            'project_id' => $this->project->id,
            'contractor_id' => $contractor->id,
            'number' => 'ACT-INVOICE-1',
            'date' => now()->toDateString(),
            'subject' => 'Works',
            'total_amount' => 5000,
            'status' => 'active',
        ]);
        $act = ContractPerformanceAct::query()->create([
            'contract_id' => $contract->id,
            'project_id' => $this->project->id,
            'act_document_number' => 'ACT-1',
            'act_date' => now()->toDateString(),
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'amount' => '1250.55',
            'vat_rate' => '20.00',
            'vat_amount' => '208.43',
            'amount_without_vat' => '1042.12',
            'currency' => 'RUB',
            'status' => ContractPerformanceAct::STATUS_DRAFT,
        ]);

        try {
            $this->service->createFromAct($act, InvoiceDirection::INCOMING);
            self::fail('Счёт был создан из неутверждённого акта');
        } catch (\DomainException) {
            self::assertDatabaseCount('payment_documents', 0);
        }

        ContractPerformanceAct::withoutEvents(static function () use ($act): void {
            $act->forceFill([
                'status' => ContractPerformanceAct::STATUS_APPROVED,
                'is_approved' => true,
            ])->save();
        });
        $locks = [];
        \Illuminate\Support\Facades\DB::listen(static function (\Illuminate\Database\Events\QueryExecuted $query) use (&$locks): void {
            if (str_contains(strtolower($query->sql), 'for update')) {
                foreach (['contracts', 'contract_performance_acts'] as $table) {
                    if (str_contains($query->sql, '"'.$table.'"')) {
                        $locks[] = $table;
                    }
                }
            }
        });
        $first = $this->service->createFromAct($act->fresh(), InvoiceDirection::INCOMING);
        self::assertSame(['contracts', 'contract_performance_acts'], array_slice($locks, 0, 2));
        $second = $this->service->createFromAct($act->fresh(), InvoiceDirection::INCOMING);
        try {
            $this->service->create([
                'organization_id' => $this->organization->id, 'contract_id' => $contract->id + 10000,
                'invoiceable_type' => ContractPerformanceAct::class, 'invoiceable_id' => $act->id,
                'amount' => '1.00', 'document_type' => 'invoice',
            ]);
            self::fail('Act from a different contract was accepted');
        } catch (\DomainException $exception) {
            self::assertSame(trans_message('payments.validation.invoice_basis_scope_mismatch'), $exception->getMessage());
        }

        self::assertSame($first->id, $second->id);
        self::assertSame('1250.55', $first->amount);
        self::assertSame('20.00', $first->vat_rate);
        self::assertSame('208.43', $first->vat_amount);
        self::assertSame('1042.12', $first->amount_without_vat);
        foreach ([['first', 'contractor', $this->organization], ['second', 'subcontractor', $this->counterparty]] as [$side, $role, $organization]) {
            \App\Models\ContractParty::create([
                'contract_id' => $contract->id, 'side' => $side, 'role' => $role,
                'linked_organization_id' => $organization->id, 'name' => $organization->name, 'snapshot' => [],
            ]);
        }
        $historical = $this->service->createFromAct($act->fresh(), InvoiceDirection::INCOMING);
        self::assertSame($first->id, $historical->id);
        self::assertSame(InvoiceDirection::INCOMING, $historical->direction);
        self::assertSame($this->counterparty->id, $historical->payer_organization_id);
        self::assertSame($this->organization->id, $historical->payee_organization_id);
        try {
            $this->service->createFromAct($act->fresh(), InvoiceDirection::OUTGOING, '1.00', 'new-key');
            self::fail('An exhausted act was invoiced again after its direction changed');
        } catch (\DomainException $exception) {
            self::assertSame(trans_message('payments.validation.invoice_amount_exceeds_act_balance'), $exception->getMessage());
        }
        self::assertSame(1, PaymentDocument::query()
            ->where('invoiceable_type', ContractPerformanceAct::class)
            ->where('invoiceable_id', $act->id)
            ->count());
        $conflict = $first->replicate();
        $conflict->document_number = $first->document_number.'-conflict';
        $conflict->direction = InvoiceDirection::OUTGOING;
        $conflict->origin_key = sprintf('performance-act:%d:outgoing:%s', $act->id, hash('sha256', 'full'));
        $conflict->save();
        try {
            $this->service->createFromAct($act->fresh(), InvoiceDirection::OUTGOING);
            self::fail('Conflicting historical invoices were silently accepted');
        } catch (\DomainException $exception) {
            self::assertSame(trans_message('payments.validation.idempotency_conflict'), $exception->getMessage());
        }
        self::assertSame(2, PaymentDocument::query()->where('invoiceable_type', ContractPerformanceAct::class)
            ->where('invoiceable_id', $act->id)->count());
    }

    public function test_contract_workflow_uses_saved_parties_and_contract_currency(): void
    {
        $executor = Contractor::query()->create([
            'organization_id' => $this->organization->id, 'name' => 'Own executor',
            'source_organization_id' => $this->organization->id,
        ]);
        $contract = Contract::query()->create([
            'contractor_id' => $executor->id,
            'organization_id' => $this->organization->id, 'project_id' => $this->project->id,
            'number' => 'WORKFLOW-PARTIES', 'date' => now()->toDateString(),
            'subject' => 'Works', 'total_amount' => 5000, 'status' => 'active',
            'contract_side_type' => 'subcontract', 'currency' => 'USD',
        ]);
        foreach ([['first', 'contractor', $this->counterparty], ['second', 'subcontractor', $this->organization]] as [$side, $role, $organization]) {
            \App\Models\ContractParty::create([
                'contract_id' => $contract->id, 'side' => $side, 'role' => $role,
                'linked_organization_id' => $organization->id, 'name' => $organization->name, 'snapshot' => [],
            ]);
        }
        $actor = User::factory()->create(['current_organization_id' => $this->organization->id]);
        $workflow = app(\App\BusinessModules\Core\Payments\Services\PaymentDocumentWorkflowService::class);
        $lockLevel = 0;
        \Illuminate\Support\Facades\DB::listen(static function (\Illuminate\Database\Events\QueryExecuted $query) use (&$lockLevel): void {
            if (str_contains($query->sql, '"contracts"') && str_contains(strtolower($query->sql), 'for update')) {
                $lockLevel = $query->connection->transactionLevel();
            }
        });
        foreach ([
            [['contract_id' => $contract->id], null],
            [['source_type' => Contract::class, 'source_id' => $contract->id], ''],
            [['invoiceable_type' => Contract::class, 'invoiceable_id' => $contract->id], 'EUR'],
        ] as [$reference, $currency]) {
            $payload = [
                ...$reference, 'project_id' => $this->project->id,
                'document_type' => PaymentDocumentType::INVOICE->value,
                'document_date' => now()->toDateString(), 'invoice_type' => InvoiceType::ADVANCE->value,
                'amount' => '100.00', 'currency' => $currency, 'status' => PaymentDocumentStatus::DRAFT->value,
                'direction' => InvoiceDirection::OUTGOING->value,
                'payer_organization_id' => $this->organization->id,
                'payee_organization_id' => $this->counterparty->id,
            ];
            $initialLevel = \Illuminate\Support\Facades\DB::transactionLevel();
            $lockLevel = 0;
            $result = $workflow->create($this->organization->id, $actor->id, $payload);
            self::assertGreaterThan($initialLevel, $lockLevel);
            $lockLevel = 0;
            $direct = $this->service->create([...$payload, 'organization_id' => $this->organization->id]);
            self::assertGreaterThan($initialLevel, $lockLevel);
            foreach ([$result['document'], $direct] as $created) {
                $document = $created->fresh();
                self::assertSame(InvoiceDirection::INCOMING, $document->direction);
                self::assertSame($this->counterparty->id, $document->payer_organization_id);
                self::assertSame($this->organization->id, $document->payee_organization_id);
                self::assertSame($currency ?: 'USD', $document->currency);
                self::assertSame($contract->id, $document->invoiceable_id);
            }
        }
        $foreign = $contract->replicate();
        $foreign->organization_id = $this->counterparty->id;
        $foreign->number = 'FOREIGN-WORKFLOW';
        $foreign->save();
        ContractPerformanceAct::query()->create([
            'id' => $contract->id, 'contract_id' => $contract->id, 'project_id' => $this->project->id,
            'act_document_number' => 'REQUEST-BASIS', 'act_date' => now()->toDateString(),
            'period_start' => now()->startOfMonth()->toDateString(), 'period_end' => now()->endOfMonth()->toDateString(),
            'amount' => '500.00', 'currency' => 'USD',
            'status' => ContractPerformanceAct::STATUS_APPROVED, 'is_approved' => true,
        ]);
        foreach ([
            ['contract_id' => $foreign->id],
            ['source_type' => Contract::class, 'source_id' => $foreign->id],
            ['invoiceable_type' => Contract::class, 'invoiceable_id' => $foreign->id],
            ['contract_id' => $contract->id, 'invoiceable_type' => Contract::class, 'invoiceable_id' => $foreign->id],
            ['contract_id' => $contract->id, 'project_id' => $this->project->id + 10000],
            ['source_type' => Contract::class],
            ['contract_id' => $contract->id, 'invoiceable_type' => ContractPerformanceAct::class],
        ] as $invalidReference) {
            try {
                $workflow->create($this->organization->id, $actor->id, [
                    ...$invalidReference, 'amount' => '100.00',
                    'document_type' => PaymentDocumentType::INVOICE->value,
                ]);
                self::fail('Invalid contract reference was accepted');
            } catch (\DomainException $exception) {
                self::assertSame(trans_message('payments.validation.invoice_basis_scope_mismatch'), $exception->getMessage());
            }
        }
        self::assertSame(6, PaymentDocument::query()->where('organization_id', $this->organization->id)->count());
        $request = app(\App\BusinessModules\Core\Payments\Services\PaymentRequestService::class)->createFromContractor([
            'organization_id' => $this->organization->id, 'contract_id' => $contract->id,
            'contractor_id' => $executor->id, 'amount' => '100.00',
        ]);
        self::assertSame('USD', $request->currency);
        self::assertStringContainsString('WORKFLOW-PARTIES', $request->payment_purpose);
        self::assertStringContainsString($contract->date->format('d.m.Y'), $request->payment_purpose);
        self::assertSame($this->counterparty->id, $request->payer_organization_id);
        self::assertSame($this->organization->id, $request->payee_organization_id);
    }

    public function test_act_can_be_invoiced_in_idempotent_partial_allocations(): void
    {
        $contractor = Contractor::query()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Partial act contractor',
            'source_organization_id' => $this->counterparty->id,
        ]);
        $contract = Contract::query()->create([
            'organization_id' => $this->organization->id,
            'project_id' => $this->project->id,
            'contractor_id' => $contractor->id,
            'number' => 'ACT-INVOICE-PARTIAL',
            'date' => now()->toDateString(),
            'subject' => 'Works',
            'total_amount' => 5000,
            'status' => 'active',
        ]);
        $act = ContractPerformanceAct::query()->create([
            'contract_id' => $contract->id,
            'project_id' => $this->project->id,
            'act_document_number' => 'ACT-PARTIAL-1',
            'act_date' => now()->toDateString(),
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'amount' => '1000.00',
            'vat_rate' => '20.00',
            'vat_amount' => '166.67',
            'amount_without_vat' => '833.33',
            'currency' => 'RUB',
            'status' => ContractPerformanceAct::STATUS_APPROVED,
            'is_approved' => true,
        ]);

        $first = $this->service->createFromAct(
            $act,
            InvoiceDirection::INCOMING,
            '333.33',
            'partial-act-invoice-20260823-0001'
        );
        $retry = $this->service->createFromAct(
            $act->fresh(),
            InvoiceDirection::INCOMING,
            '333.33',
            'partial-act-invoice-20260823-0001'
        );
        $second = $this->service->createFromAct(
            $act->fresh(),
            InvoiceDirection::INCOMING,
            '333.33',
            'partial-act-invoice-20260823-0002'
        );
        $third = $this->service->createFromAct(
            $act->fresh(),
            InvoiceDirection::INCOMING,
            '333.34',
            'partial-act-invoice-20260823-0003'
        );

        self::assertSame($first->id, $retry->id);
        self::assertNotSame($first->id, $second->id);
        self::assertSame('333.33', $first->amount);
        self::assertSame('333.34', $third->amount);
        self::assertSame(3, PaymentDocument::query()
            ->where('invoiceable_type', ContractPerformanceAct::class)
            ->where('invoiceable_id', $act->id)
            ->count());
        self::assertSame(1000.0, (float) PaymentDocument::query()
            ->where('invoiceable_type', ContractPerformanceAct::class)
            ->where('invoiceable_id', $act->id)
            ->sum('amount'));
        self::assertSame(833.33, (float) PaymentDocument::query()
            ->where('invoiceable_type', ContractPerformanceAct::class)
            ->where('invoiceable_id', $act->id)
            ->sum('amount_without_vat'));
        self::assertSame(166.67, (float) PaymentDocument::query()
            ->where('invoiceable_type', ContractPerformanceAct::class)
            ->where('invoiceable_id', $act->id)
            ->sum('vat_amount'));

        $this->expectException(\DomainException::class);
        $this->service->createFromAct(
            $act->fresh(),
            InvoiceDirection::INCOMING,
            '0.01',
            'partial-act-invoice-20260823-0004'
        );
    }

    public function test_payment_paid_event_does_not_complete_material_site_request_without_delivery(): void
    {
        Event::fake([SiteRequestStatusChanged::class]);

        $user = User::factory()->create([
            'current_organization_id' => $this->organization->id,
        ]);

        $siteRequest = SiteRequest::query()->create([
            'organization_id' => $this->organization->id,
            'project_id' => $this->project->id,
            'user_id' => $user->id,
            'title' => 'Material request awaiting delivery',
            'request_type' => SiteRequestTypeEnum::MATERIAL_REQUEST->value,
            'status' => SiteRequestStatusEnum::APPROVED->value,
            'priority' => 'medium',
            'material_name' => 'Concrete B25',
            'material_quantity' => 5,
            'material_unit' => 'm3',
        ]);

        $item = $this->createEstimateItem([
            'quantity' => 5,
            'unit_price' => 200,
            'total_amount' => 1000,
        ]);

        $document = $this->createDocumentWithSplit($item, [
            'document_number' => 'PAY-EST-007',
            'status' => PaymentDocumentStatus::PAID->value,
            'paid_amount' => 1000,
            'created_by_user_id' => $user->id,
        ]);

        $document->siteRequests()->attach($siteRequest->id, ['amount' => 1000]);

        app(CompleteSiteRequestsOnPaymentPaid::class)->handle(
            new PaymentDocumentPaid(
                document: $document->fresh('siteRequests'),
                amount: 1000,
                transactionId: 1,
                recognizedAt: $document->paid_at,
                organizationId: (int) $document->organization_id,
                projectId: $document->project_id === null ? null : (int) $document->project_id,
                invoiceableType: $document->invoiceable_type,
                invoiceableId: $document->invoiceable_id === null ? null : (int) $document->invoiceable_id,
                currency: $document->currency,
            )
        );

        $this->assertSame(
            SiteRequestStatusEnum::APPROVED,
            $siteRequest->fresh()->status
        );
    }

    public function test_warehouse_receipt_from_payment_is_idempotent_per_estimate_split(): void
    {
        $material = $this->createMaterial();

        OrganizationWarehouse::query()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Main warehouse',
            'code' => 'WH-MAIN',
            'warehouse_type' => OrganizationWarehouse::TYPE_CENTRAL,
            'is_main' => true,
            'is_active' => true,
        ]);

        $item = $this->createEstimateItem([
            'item_type' => EstimatePositionItemType::MATERIAL->value,
            'material_id' => $material->id,
            'measurement_unit_id' => $material->measurement_unit_id,
            'quantity' => 10,
            'unit_price' => 100,
            'total_amount' => 1000,
        ]);

        $document = $this->createDocumentWithSplit($item, [
            'document_number' => 'PAY-EST-008',
            'status' => PaymentDocumentStatus::PAID->value,
            'paid_amount' => 1000,
            'paid_at' => now(),
        ]);

        $service = app(WarehouseReceiptFromPaymentService::class);
        $service->createFromPaymentDocument($document);
        $service->createFromPaymentDocument($document->fresh());

        $this->assertSame(
            1,
            WarehouseMovement::query()
                ->where('movement_type', WarehouseMovement::TYPE_RECEIPT)
                ->where('document_number', $document->document_number)
                ->where('material_id', $material->id)
                ->count()
        );
    }

    public function test_paid_payment_document_workflow_is_not_blocked_by_missing_bank_details(): void
    {
        $item = $this->createEstimateItem([
            'quantity' => 10,
            'unit_price' => 100,
            'total_amount' => 1000,
        ]);

        $document = $this->createDocumentWithSplit($item, [
            'document_number' => 'PAY-EST-009',
            'document_type' => PaymentDocumentType::PAYMENT_ORDER->value,
            'status' => PaymentDocumentStatus::PAID->value,
            'paid_amount' => 1000,
            'remaining_amount' => 0,
            'bank_account' => null,
            'bank_bik' => null,
        ]);

        $payload = app(PaymentDocumentPresenter::class)->detailed($document->fresh(), null);
        $flags = $payload['problem_flags'];
        $summary = $payload['workflow_summary'];

        $this->assertSame([], $flags);
        $this->assertSame('paid', $summary['current_stage']);
        $this->assertNull($summary['next_action']);
        $this->assertFalse($summary['is_blocked']);
    }

    public function test_documents_can_be_filtered_by_estimate_splits(): void
    {
        $targetItem = $this->createEstimateItem([
            'quantity' => 10,
            'unit_price' => 100,
            'total_amount' => 1000,
        ]);

        $otherEstimate = Estimate::query()->create([
            'organization_id' => $this->organization->id,
            'project_id' => $this->project->id,
            'number' => 'EST-002',
            'name' => 'Other estimate',
            'estimate_date' => now()->toDateString(),
        ]);

        $otherItem = EstimateItem::query()->create([
            'estimate_id' => $otherEstimate->id,
            'position_number' => '1',
            'name' => 'Other work',
            'quantity' => 5,
            'unit_price' => 100,
            'total_amount' => 500,
        ]);

        $targetDocument = $this->createDocumentWithSplit($targetItem, [
            'document_number' => 'PAY-EST-003',
            'status' => PaymentDocumentStatus::APPROVED->value,
        ]);

        $this->createDocumentWithSplit($otherItem, [
            'document_number' => 'PAY-EST-004',
            'status' => PaymentDocumentStatus::APPROVED->value,
            'amount' => 500,
            'estimate_splits' => [
                [
                    'estimate_item_id' => $otherItem->id,
                    'quantity' => 5,
                    'unit_price_actual' => 100,
                    'amount' => 500,
                    'percentage' => 100,
                ],
            ],
        ]);

        $documents = $this->service->getForOrganization($this->organization->id, [
            'estimate_id' => $this->estimate->id,
        ]);

        $this->assertCount(1, $documents);
        $this->assertSame($targetDocument->id, $documents->first()->id);
    }

    public function test_estimate_splits_require_contract_or_act_basis(): void
    {
        $item = $this->createEstimateItem([
            'quantity' => 10,
            'unit_price' => 100,
            'total_amount' => 1000,
        ]);

        try {
            $this->createDocumentWithSplit($item, [
                'document_number' => 'PAY-EST-005',
                'invoiceable_type' => null,
                'invoiceable_id' => null,
                'source_type' => null,
                'source_id' => null,
            ]);

            $this->fail('Payment document with estimate splits must require a contract or act basis.');
        } catch (\DomainException $exception) {
            $this->assertSame(
                trans_message('payments.validation.estimate_split_source_required'),
                $exception->getMessage()
            );
        }

        $this->assertDatabaseMissing('payment_document_estimate_splits', [
            'estimate_item_id' => $item->id,
        ]);
    }

    private function createEstimateItem(array $overrides = []): EstimateItem
    {
        return EstimateItem::query()->create(array_merge([
            'estimate_id' => $this->estimate->id,
            'item_type' => EstimatePositionItemType::WORK->value,
            'position_number' => '1',
            'name' => 'Test work',
            'quantity' => 1,
            'unit_price' => 100,
            'total_amount' => 100,
        ], $overrides));
    }

    private function createMaterial(): Material
    {
        $unit = MeasurementUnit::query()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Pieces',
            'short_name' => 'pcs',
            'type' => 'material',
            'is_default' => true,
            'is_system' => false,
        ]);

        return Material::query()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Test material',
            'code' => 'MAT-TEST',
            'measurement_unit_id' => $unit->id,
            'default_price' => 100,
            'is_active' => true,
        ]);
    }

    private function createDocumentWithSplit(EstimateItem $item, array $overrides = []): PaymentDocument
    {
        $data = array_merge([
            'organization_id' => $this->organization->id,
            'project_id' => $this->project->id,
            'document_type' => PaymentDocumentType::INVOICE->value,
            'document_number' => 'PAY-EST',
            'document_date' => now()->toDateString(),
            'direction' => InvoiceDirection::OUTGOING->value,
            'invoice_type' => InvoiceType::ACT->value,
            'invoiceable_type' => \App\Models\ContractPerformanceAct::class,
            'invoiceable_id' => null,
            'payer_organization_id' => $this->organization->id,
            'payee_organization_id' => $this->counterparty->id,
            'amount' => 1000,
            'currency' => 'RUB',
            'vat_rate' => 20,
            'estimate_splits' => [
                [
                    'estimate_item_id' => $item->id,
                    'quantity' => 10,
                    'unit_price_actual' => 100,
                    'amount' => 1000,
                    'percentage' => 100,
                ],
            ],
        ], $overrides);

        if (($data['invoiceable_type'] ?? null) === ContractPerformanceAct::class
            && empty($data['invoiceable_id'])) {
            $contractor = Contractor::query()->create([
                'organization_id' => $this->organization->id,
                'name' => 'Payment basis contractor',
                'source_organization_id' => $this->counterparty->id,
            ]);
            $contract = Contract::query()->create([
                'organization_id' => $this->organization->id,
                'project_id' => $this->project->id,
                'contractor_id' => $contractor->id,
                'number' => 'PAY-BASIS-'.uniqid('', true),
                'date' => now()->toDateString(),
                'subject' => 'Payment basis',
                'total_amount' => $data['amount'],
                'status' => 'active',
            ]);
            $act = ContractPerformanceAct::query()->create([
                'contract_id' => $contract->id,
                'project_id' => $this->project->id,
                'act_document_number' => 'PAY-ACT-'.uniqid('', true),
                'act_date' => now()->toDateString(),
                'period_start' => now()->startOfMonth()->toDateString(),
                'period_end' => now()->endOfMonth()->toDateString(),
                'amount' => $data['amount'],
                'vat_rate' => '20.00',
                'vat_amount' => '0.00',
                'amount_without_vat' => $data['amount'],
                'currency' => 'RUB',
                'status' => ContractPerformanceAct::STATUS_APPROVED,
                'is_approved' => true,
            ]);
            $data['invoiceable_id'] = $act->id;
            $data['idempotency_key'] = 'estimate-payment:'.$act->id.':'.$data['document_number'];
        }

        $requestedStatus = $data['status'] ?? null;
        $document = $this->service->create($data);

        if (is_string($requestedStatus) && $document->status->value !== $requestedStatus) {
            PaymentDocument::withoutEvents(static function () use ($document, $requestedStatus): void {
                $document->forceFill(['status' => $requestedStatus])->save();
            });
        }

        return $document->fresh();
    }
}
