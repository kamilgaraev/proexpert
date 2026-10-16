<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessModules\Core\Payments;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Enums\PaymentMethod;
use App\BusinessModules\Core\Payments\Enums\PaymentTransactionStatus;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use App\BusinessModules\Core\Payments\Services\PaymentBudgetLimitService;
use App\BusinessModules\Features\Budgeting\Models\BudgetAmount;
use App\BusinessModules\Features\Budgeting\Models\BudgetArticle;
use App\BusinessModules\Features\Budgeting\Models\BudgetLimitReservation;
use App\BusinessModules\Features\Budgeting\Models\BudgetLine;
use App\BusinessModules\Features\Budgeting\Models\BudgetPeriod;
use App\BusinessModules\Features\Budgeting\Models\BudgetScenario;
use App\BusinessModules\Features\Budgeting\Models\BudgetVersion;
use App\BusinessModules\Features\Budgeting\Models\ResponsibilityCenter;
use App\Domain\Authorization\Services\ModulePermissionChecker;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\ContractPerformanceAct;
use App\Models\Module;
use App\Models\OrganizationModuleActivation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AdminApiTestContext;
use Tests\Support\EnablesImmutableAuditWriter;
use Tests\TestCase;

final class PaymentBudgetLimitLifecycleTest extends TestCase
{
    use EnablesImmutableAuditWriter;
    use RefreshDatabase;

    public function test_contract_advance_runs_real_approval_and_payment_lifecycle_once(): void
    {
        $this->enableImmutableAuditWriter();
        \Illuminate\Support\Facades\Notification::fake();
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activateBudgetingModule($context->organization->id);
        $this->activatePaymentsModule($context->organization->id);
        $budget = $this->createBudgetLine($context->organization->id, 10000.0);
        $contractor = \App\Models\Contractor::query()->create(['organization_id' => $context->organization->id, 'name' => 'Подрядчик']);
        $project = \App\Models\Project::factory()->create(['organization_id' => $context->organization->id]);
        $contract = \App\Models\Contract::query()->create([
            'organization_id' => $context->organization->id,
            'contractor_id' => $contractor->id,
            'project_id' => $project->id,
            'number' => 'ADVANCE-LIFECYCLE',
            'date' => now()->toDateString(),
            'status' => 'draft',
            'base_amount' => 10000,
            'total_amount' => 10000,
            'planned_advance_amount' => 5000,
        ]);
        $service = app(\App\Services\Contract\ContractPaymentDocumentService::class);
        $data = [
            'amount' => '5000.00',
            'payment_type' => 'advance',
            'payment_date' => now()->toDateString(),
            'budget_article_id' => $budget['article']->id,
            'responsibility_center_id' => $budget['center']->id,
            'created_by_user_id' => $context->user->id,
            'idempotency_key' => 'contract-advance-lifecycle',
        ];

        foreach ([$budget['article']->id, (string) $budget['article']->id, $budget['article']->uuid] as $identifier) {
            $normalized = $this->service()->normalizeDocumentData(['budget_article_id' => $identifier], $context->organization->id);
            $this->assertSame($budget['article']->id, $normalized['budget_article_id']);
        }
        foreach (['not-a-uuid', false, [], '999999999999999999999999'] as $identifier) {
            try {
                $this->service()->normalizeDocumentData(['budget_article_id' => $identifier], $context->organization->id);
                $this->fail('Invalid budget identifier must be rejected.');
            } catch (\App\BusinessModules\Core\Payments\Exceptions\PaymentBudgetLimitException) {
                $this->addToAssertionCount(1);
            }
        }
        $otherOrganization = \App\Models\Organization::factory()->create();
        try {
            $this->service()->normalizeDocumentData(['budget_article_id' => $budget['article']->uuid], $otherOrganization->id);
            $this->fail('Another organization must not resolve the budget article.');
        } catch (\App\BusinessModules\Core\Payments\Exceptions\PaymentBudgetLimitException) {
            $this->addToAssertionCount(1);
        }

        $document = $service->createPaidContractPayment($contract, $data);
        $this->assertSame(PaymentDocumentStatus::PAID, $document->status);
        $this->assertEquals('5000.00', $document->paid_amount);
        $this->assertEquals('0.00', $document->remaining_amount);
        $this->assertSame($document->id, $service->createPaidContractPayment($contract, $data)->id);
        $this->assertSame(1, PaymentTransaction::query()->where('payment_document_id', $document->id)->count());
    }

    public function test_check_returns_available_balance_for_payment_document(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activateBudgetingModule($context->organization->id);
        $budget = $this->createBudgetLine($context->organization->id, 1000.0);
        $document = $this->createDocument($context, $budget, 400.0);

        $check = $this->service()->check($document, $context->user);

        $this->assertSame('available', $check['status']);
        $this->assertSame('allow', $check['decision']);
        $this->assertSame('600.00', $check['summary']['available_after_request']);
    }

    public function test_act_invoice_uses_its_contract_scoped_approved_budget(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $this->activateBudgetingModule($context->organization->id);
        $budget = $this->createBudgetLine($context->organization->id, 100000.0);
        $act = $this->createApprovedAct($context);
        $contract = $act->contract;
        $project = $contract->project;
        $contractor = $contract->contractor;
        $budget['line']->forceFill(['project_id' => $project->id, 'contract_id' => $contract->id])->save();
        $budget['line']->version->forceFill(['status' => 'approved', 'activated_at' => null])->save();
        $payload = [
            'document_type' => 'invoice',
            'document_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'amount' => 100000,
            'currency' => 'RUB',
            'project_id' => $project->id,
            'payee_contractor_id' => $contractor->id,
            'payer_organization_id' => $context->organization->id,
            'invoiceable_type' => ContractPerformanceAct::class,
            'invoiceable_id' => $act->id,
            'invoice_type' => 'act',
            'direction' => 'outgoing',
            'budget_article_id' => $budget['article']->id,
            'responsibility_center_id' => $budget['center']->id,
            'idempotency_key' => 'act-contract-budget-scope-regression',
        ];

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/payments/documents', $payload);

        $response->assertCreated();
        $document = PaymentDocument::query()->findOrFail($response->json('data.id'));
        $this->assertSame($act->id, (int) $document->invoiceable_id);
        $check = $this->service()->check($document->fresh(), $context->user);
        $this->assertContains($check['decision'], ['allow', 'warn']);
        $this->assertTrue($check['limit']['has_approved_budget']);
        $this->assertSame('100000.00', $check['sources']['approved_budget']);
        $this->assertDatabaseHas('budget_limit_checks', ['payment_document_id' => $document->id, 'contract_id' => $contract->id, 'accepted' => true]);
        $repeat = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/payments/documents', $payload);
        $repeat->assertCreated()->assertJsonPath('data.id', $document->id);
        $this->assertDatabaseCount('payment_documents', 1);
        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public function test_request_order_retains_its_contract_scoped_budget_on_accept_and_retry(): void
    {
        $this->travelTo(now()->startOfMonth()->addDays(10)->setTime(9, 0));
        \Illuminate\Support\Facades\Notification::fake();
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $this->activateBudgetingModule($context->organization->id);
        $budget = $this->createBudgetLine($context->organization->id, 1000.0);
        $contract = $this->createApprovedAct($context)->contract;
        $budget['line']->forceFill(['project_id' => $contract->project_id, 'contract_id' => $contract->id])->save();
        $request = $this->createDocument($context, $budget, 900.0);
        $request->forceFill([
            'document_type' => PaymentDocumentType::PAYMENT_REQUEST,
            'status' => PaymentDocumentStatus::APPROVED,
            'project_id' => $contract->project_id,
            'payer_organization_id' => $context->organization->id,
            'payee_contractor_id' => $contract->contractor_id,
            'source_type' => Contract::class,
            'source_id' => $contract->id,
            'invoiceable_type' => Contract::class,
            'invoiceable_id' => $contract->id,
            'currency' => 'RUB',
            'due_date' => now()->addDays(3)->toDateString(),
        ])->save();

        $this->service()->syncReservation($request->fresh(), $context->user);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/payments/requests/{$request->id}/accept");
        $response->assertOk();
        $orderId = $response->json('data.payment_order.id');
        $order = PaymentDocument::query()->findOrFail($orderId);
        self::assertSame(Contract::class, $order->invoiceable_type);
        self::assertSame($contract->id, (int) $order->invoiceable_id);
        self::assertSame(PaymentDocument::class, $order->source_type);
        self::assertSame($request->id, (int) $order->source_id);
        $this->assertDatabaseHas('budget_limit_checks', [
            'payment_document_id' => $orderId,
            'contract_id' => $contract->id,
            'accepted' => true,
        ]);
        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/payments/requests/{$request->id}/accept")
            ->assertOk()->assertJsonPath('data.payment_order.id', $orderId);
        self::assertSame(2, PaymentDocument::query()->count());
        $this->assertDatabaseHas('budget_limit_reservations', [
            'payment_document_id' => $request->id,
            'status' => BudgetLimitReservation::STATUS_RELEASED,
        ]);
        $this->assertDatabaseHas('budget_limit_reservations', [
            'payment_document_id' => $orderId,
            'status' => BudgetLimitReservation::STATUS_RESERVED,
            'amount' => 900,
        ]);
        self::assertSame('100.00', $this->service()->check($order->fresh(), $context->user)['summary']['available_after_request']);
        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public function test_generic_request_order_creation_inherits_contract_budget_and_replaces_its_reservation(): void
    {
        $this->travelTo(now()->startOfMonth()->addDays(10)->setTime(9, 0));
        \Illuminate\Support\Facades\Notification::fake();
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $this->activateBudgetingModule($context->organization->id);
        $budget = $this->createBudgetLine($context->organization->id, 1000.0);
        $contract = $this->createApprovedAct($context)->contract;
        $budget['line']->forceFill(['project_id' => $contract->project_id, 'contract_id' => $contract->id])->save();
        $request = $this->createDocument($context, $budget, 900.0);
        $request->forceFill([
            'document_type' => PaymentDocumentType::PAYMENT_REQUEST,
            'status' => PaymentDocumentStatus::APPROVED,
            'project_id' => $contract->project_id,
            'payer_organization_id' => $context->organization->id,
            'payee_contractor_id' => $contract->contractor_id,
            'invoiceable_type' => Contract::class,
            'invoiceable_id' => $contract->id,
            'currency' => 'RUB',
            'due_date' => now()->addDays(3)->toDateString(),
            'bank_account' => '40702810000000000001',
            'bank_bik' => '044525225',
        ])->save();
        $this->service()->syncReservation($request->fresh(), $context->user);

        $payload = [
            'document_type' => PaymentDocumentType::PAYMENT_ORDER->value,
            'document_date' => now()->toDateString(),
            'due_date' => $request->due_date->toDateString(),
            'amount' => 900,
            'currency' => 'RUB',
            'payment_purpose' => 'Учебное поручение по требованию',
            'source_type' => PaymentDocument::class,
            'source_id' => $request->id,
        ];
        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/payments/documents', $payload + ['bank_account' => '40702810000000000002'])
            ->assertUnprocessable();
        $this->assertDatabaseHas('budget_limit_reservations', [
            'payment_document_id' => $request->id, 'status' => BudgetLimitReservation::STATUS_RESERVED, 'amount' => 900,
        ]);
        self::assertSame(0, PaymentDocument::query()->where('source_type', PaymentDocument::class)->where('source_id', $request->id)->count());
        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/payments/documents', $payload + ['vat_rate' => 0])->assertUnprocessable();
        $this->assertDatabaseHas('budget_limit_reservations', [
            'payment_document_id' => $request->id, 'status' => BudgetLimitReservation::STATUS_RESERVED, 'amount' => 900,
        ]);
        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/payments/documents', $payload)->assertCreated();
        $orderId = $response->json('data.id');
        $this->assertDatabaseHas('payment_documents', [
            'id' => $orderId, 'invoiceable_type' => Contract::class, 'invoiceable_id' => $contract->id,
            'budget_article_id' => $budget['article']->id, 'responsibility_center_id' => $budget['center']->id,
            'bank_account' => $request->bank_account, 'bank_bik' => $request->bank_bik,
        ]);
        $this->assertDatabaseHas('budget_limit_checks', [
            'payment_document_id' => $orderId, 'contract_id' => $contract->id, 'accepted' => true,
        ]);
        $this->assertDatabaseHas('budget_limit_reservations', [
            'payment_document_id' => $request->id, 'status' => BudgetLimitReservation::STATUS_RELEASED,
        ]);
        $this->assertDatabaseHas('budget_limit_reservations', [
            'payment_document_id' => $orderId, 'status' => BudgetLimitReservation::STATUS_RESERVED, 'amount' => 900,
        ]);
        $order = PaymentDocument::query()->findOrFail($orderId);
        self::assertSame('100.00', $this->service()->check($order, $context->user)['summary']['available_after_request']);
        self::assertSame('100.00', $this->service()->check($request->fresh(), $context->user)['summary']['available_after_request']);
        $probe = $this->createDocument($context, $budget, 100.0);
        $probe->forceFill([
            'project_id' => $contract->project_id, 'currency' => 'RUB',
            'payer_organization_id' => $context->organization->id, 'payee_contractor_id' => $contract->contractor_id,
            'invoiceable_type' => Contract::class, 'invoiceable_id' => $contract->id,
            'due_date' => $request->due_date,
        ])->save();
        self::assertSame('0.00', $this->service()->check($probe->fresh(), $context->user)['summary']['available_after_request']);
        $this->service()->syncReservation($probe->fresh(), $context->user);
        $this->assertDatabaseMissing('budget_limit_reservations', ['payment_document_id' => $probe->id, 'status' => BudgetLimitReservation::STATUS_RESERVED]);
        $this->withHeaders($context->authHeaders())->putJson("/api/v1/admin/payments/documents/{$orderId}", ['amount' => 450])
            ->assertUnprocessable();
        self::assertSame(900.0, (float) $order->fresh()->amount);
        $this->withHeaders($context->authHeaders())->postJson("/api/v1/admin/payments/requests/{$request->id}/accept")
            ->assertOk()->assertJsonPath('data.payment_order.id', $orderId);
        self::assertSame(900.0, (float) BudgetLimitReservation::query()->where('status', BudgetLimitReservation::STATUS_RESERVED)->sum('amount'));
        app(\App\BusinessModules\Core\Payments\Services\PaymentDocumentService::class)->submit($order->fresh(), $context->user);
        self::assertSame('100.00', $this->service()->check($order->fresh(), $context->user)['summary']['available_after_request']);
        $this->assertDatabaseCount('payment_documents', 3);
        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public function test_bank_completion_preserves_an_existing_exception_only_for_unchanged_financial_context(): void
    {
        $this->travelTo(now()->startOfMonth()->addDays(10)->setTime(9, 0));
        \Illuminate\Support\Facades\Notification::fake();
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $this->activateBudgetingModule($context->organization->id);
        $budget = $this->createBudgetLine($context->organization->id, 500.0);
        $contract = $this->createApprovedAct($context)->contract;
        $budget['line']->forceFill(['project_id' => $contract->project_id, 'contract_id' => $contract->id])->save();
        $request = $this->createDocument($context, $budget, 900.0);
        $request->forceFill([
            'document_type' => PaymentDocumentType::PAYMENT_REQUEST, 'status' => PaymentDocumentStatus::APPROVED,
            'project_id' => $contract->project_id, 'payer_organization_id' => $context->organization->id,
            'payee_contractor_id' => $contract->contractor_id,
            'invoiceable_type' => Contract::class, 'invoiceable_id' => $contract->id,
            'currency' => 'RUB', 'due_date' => now()->addDays(3)->toDateString(),
            'payment_purpose' => 'Учебное поручение без фактической оплаты',
        ])->save();
        $reason = 'Учебное превышение без фактической оплаты';
        $this->service()->syncReservation($request->fresh(), $context->user, $reason);
        $created = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/payments/requests/{$request->id}/accept", ['budget_override_reason' => $reason])->assertOk();
        $order = PaymentDocument::findOrFail($created->json('data.payment_order.id'));

        $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/payments/documents/{$order->id}", [
                'bank_account' => '00000000000000000000', 'bank_bik' => '000000000',
                'bank_name' => 'Учебные реквизиты — не для оплаты',
                'due_date' => $order->due_date->toDateString(),
                'notes' => 'Учебные недействительные реквизиты.',
            ])->assertOk();
        self::assertSame($reason, $order->fresh()->budget_limit_override_reason);
        self::assertNull($request->fresh()->bank_account);
        self::assertSame(1, BudgetLimitReservation::query()->where('payment_document_id', $order->id)
            ->where('status', BudgetLimitReservation::STATUS_RESERVED)->count());
        $this->assertDatabaseHas('budget_limit_reservations', [
            'payment_document_id' => $order->id, 'status' => BudgetLimitReservation::STATUS_RESERVED, 'amount' => 900,
        ]);
        $this->assertDatabaseMissing('budget_limit_reservations', [
            'payment_document_id' => $request->id, 'status' => BudgetLimitReservation::STATUS_RESERVED,
        ]);
        $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/payments/documents/{$order->id}", ['due_date' => now()->addDays(4)->toDateString()])
            ->assertUnprocessable();
        self::assertSame(now()->addDays(3)->toDateString(), $order->fresh()->due_date->toDateString());
        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public function test_unscoped_budget_includes_reservations_from_contracts_and_counterparties(): void
    {
        $this->travelTo(now()->startOfMonth()->addDays(10)->setTime(9, 0));
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activateBudgetingModule($context->organization->id);
        $budget = $this->createBudgetLine($context->organization->id, 1000.0);
        $firstContract = $this->createApprovedAct($context)->contract;
        $secondContract = $this->createApprovedAct($context)->contract;
        $reserved = $this->createDocument($context, $budget, 900.0);
        $reserved->forceFill([
            'status' => PaymentDocumentStatus::APPROVED, 'currency' => 'RUB',
            'project_id' => $firstContract->project_id, 'payee_contractor_id' => $firstContract->contractor_id,
            'invoiceable_type' => Contract::class, 'invoiceable_id' => $firstContract->id,
        ])->save();
        $this->service()->syncReservation($reserved->fresh(), $context->user);
        $probe = $this->createDocument($context, $budget, 100.0);
        $probe->forceFill([
            'currency' => 'RUB', 'project_id' => $secondContract->project_id,
            'payee_contractor_id' => $secondContract->contractor_id,
            'invoiceable_type' => Contract::class, 'invoiceable_id' => $secondContract->id,
        ])->save();

        self::assertSame('0.00', $this->service()->check($probe->fresh(), $context->user)['summary']['available_after_request']);
        $this->assertDatabaseHas('budget_limit_reservations', ['payment_document_id' => $reserved->id, 'amount' => 900, 'status' => BudgetLimitReservation::STATUS_RESERVED]);
    }

    #[DataProvider('conflictingContractContexts')]
    public function test_order_creation_rejects_conflicting_contract_context(string $change, bool $accept): void
    {
        $this->travelTo(now()->startOfMonth()->addDays(10)->setTime(9, 0));
        \Illuminate\Support\Facades\Notification::fake();
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $this->activateBudgetingModule($context->organization->id);
        $budget = $this->createBudgetLine($context->organization->id, 100000.0);
        $contract = $this->createApprovedAct($context)->contract;
        $budget['line']->forceFill(['project_id' => $contract->project_id, 'contract_id' => $contract->id])->save();
        $recipient = \App\Models\Organization::factory()->create();
        $firstParty = \App\Models\ContractParty::query()->create([
            'contract_id' => $contract->id, 'side' => 'first', 'role' => 'customer',
            'party_key' => 'first',
            'linked_organization_id' => $context->organization->id, 'name' => 'Заказчик', 'snapshot' => [],
        ]);
        $secondParty = \App\Models\ContractParty::query()->create([
            'contract_id' => $contract->id, 'side' => 'second', 'role' => 'contractor',
            'party_key' => 'second',
            'linked_organization_id' => $recipient->id, 'name' => 'Подрядчик', 'snapshot' => [],
        ]);
        $request = $this->createDocument($context, $budget, 900.0);
        $request->forceFill([
            'document_type' => PaymentDocumentType::PAYMENT_REQUEST,
            'status' => PaymentDocumentStatus::APPROVED,
            'project_id' => $contract->project_id,
            'payer_organization_id' => $context->organization->id,
            'payee_organization_id' => $recipient->id,
            'invoiceable_type' => Contract::class, 'invoiceable_id' => $contract->id,
            'currency' => 'RUB', 'due_date' => now()->addDays(3)->toDateString(),
        ])->save();
        if ($change === 'counterparty') {
            $request->update(['contractor_id' => Contractor::query()->create([
                'organization_id' => $context->organization->id, 'name' => 'Иной контрагент утверждённого требования',
            ])->id]);
        }
        $this->service()->syncReservation($request->fresh(), $context->user);
        if ($change === 'direction') {
            $firstParty->update(['linked_organization_id' => $recipient->id]);
            $secondParty->update(['linked_organization_id' => $context->organization->id]);
        } elseif ($change === 'recipient') {
            $secondParty->update(['linked_organization_id' => \App\Models\Organization::factory()->create()->id]);
        }

        $response = $accept
            ? $this->withHeaders($context->authHeaders())->postJson("/api/v1/admin/payments/requests/{$request->id}/accept")
            : $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/payments/documents', [
                'document_type' => PaymentDocumentType::PAYMENT_ORDER->value,
                'document_date' => now()->toDateString(), 'due_date' => $request->due_date->toDateString(),
                'amount' => 900, 'source_type' => PaymentDocument::class, 'source_id' => $request->id,
            ]);

        $response->assertUnprocessable();
        $this->assertDatabaseCount('payment_documents', 1);
        $this->assertDatabaseHas('budget_limit_reservations', [
            'payment_document_id' => $request->id, 'status' => BudgetLimitReservation::STATUS_RESERVED, 'amount' => 900,
        ]);
        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public static function conflictingContractContexts(): array
    {
        return [
            'recipient_accept' => ['recipient', true],
            'direction_accept' => ['direction', true],
            'recipient_generic' => ['recipient', false],
            'direction_generic' => ['direction', false],
            'counterparty_accept' => ['counterparty', true],
            'counterparty_generic' => ['counterparty', false],
        ];
    }

    #[DataProvider('legacyRequestOrders')]
    public function test_accepting_a_legacy_existing_order_checks_compatibility_before_releasing_reservation(
        PaymentDocumentStatus $orderStatus,
        ?string $mismatch,
        bool $accepted,
    ): void
    {
        $this->travelTo(now()->startOfMonth()->addDays(10)->setTime(9, 0));
        \Illuminate\Support\Facades\Notification::fake();
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $this->activateBudgetingModule($context->organization->id);
        $budget = $this->createBudgetLine($context->organization->id, 100000.0);
        $contract = $this->createApprovedAct($context)->contract;
        $budget['line']->forceFill(['project_id' => $contract->project_id, 'contract_id' => $contract->id])->save();
        $request = $this->createDocument($context, $budget, 900.0);
        $request->forceFill([
            'document_type' => PaymentDocumentType::PAYMENT_REQUEST,
            'status' => PaymentDocumentStatus::APPROVED,
            'project_id' => $contract->project_id,
            'payer_organization_id' => $context->organization->id,
            'payee_contractor_id' => $contract->contractor_id,
            'invoiceable_type' => Contract::class,
            'invoiceable_id' => $contract->id,
            'currency' => 'RUB',
            'due_date' => now()->addDays(3)->toDateString(),
        ])->save();
        $otherCounterparty = Contractor::query()->create([
            'organization_id' => $context->organization->id, 'name' => 'Другой бюджетный контрагент',
        ]);
        if ($mismatch === 'missing_counterparty') {
            $request->update(['contractor_id' => $otherCounterparty->id]);
        }
        $this->service()->syncReservation($request->fresh(), $context->user);
        $orderBudget = $budget;
        $orderContractId = $contract->id;
        if ($mismatch === 'article') {
            $orderBudget['article'] = BudgetArticle::query()->create([
                'organization_id' => $context->organization->id,
                'code' => 'OTHER-PAY-'.uniqid(),
                'name' => 'Другая статья',
                'budget_kind' => 'bdds',
                'flow_direction' => 'outflow',
                'is_leaf' => true,
                'is_active' => true,
            ]);
        }
        if ($mismatch === 'center') {
            $orderBudget['center'] = ResponsibilityCenter::query()->create([
                'organization_id' => $context->organization->id,
                'center_type' => 'project',
                'code' => 'OTHER-CFO-'.uniqid(),
                'name' => 'Другой ЦФО',
                'is_active' => true,
            ]);
        }
        if ($mismatch === 'contract') {
            $orderContractId = Contract::query()->create([
                'organization_id' => $context->organization->id,
                'project_id' => $contract->project_id,
                'contractor_id' => $contract->contractor_id,
                'number' => 'OTHER-LIMIT-'.uniqid(),
                'date' => now()->toDateString(),
                'status' => 'active',
                'total_amount' => 100000,
            ])->id;
        }
        if ($mismatch === 'missing_contract') {
            $orderContractId = null;
        }
        $hasBudgetMismatch = in_array($mismatch, ['article', 'center', 'contract', 'missing_contract'], true);
        if ($hasBudgetMismatch) {
            $orderBudget['line'] = BudgetLine::query()->create([
                'budget_version_id' => $budget['line']->budget_version_id,
                'project_id' => $contract->project_id,
                'contract_id' => $orderContractId,
                'budget_article_id' => $orderBudget['article']->id,
                'responsibility_center_id' => $orderBudget['center']->id,
                'currency' => 'RUB',
            ]);
            BudgetAmount::query()->create([
                'budget_line_id' => $orderBudget['line']->id,
                'month' => now()->startOfMonth()->toDateString(),
                'plan_amount' => 100000,
                'forecast_amount' => 100000,
                'currency' => 'RUB',
            ]);
        }
        $existingOrder = $this->createDocument($context, $orderBudget, 900.0);
        $existingOrder->forceFill([
            'document_type' => PaymentDocumentType::PAYMENT_ORDER,
            'status' => $orderStatus,
            'contractor_id' => $mismatch === 'counterparty'
                ? $otherCounterparty->id
                : ($mismatch === 'canonical_counterparty' ? $contract->contractor_id : null),
            'direction' => $mismatch === 'direction' ? InvoiceDirection::INCOMING : InvoiceDirection::OUTGOING,
            'project_id' => $contract->project_id,
            'payer_organization_id' => $context->organization->id,
            'payee_contractor_id' => $contract->contractor_id,
            'invoiceable_type' => $orderContractId !== null ? Contract::class : null,
            'invoiceable_id' => $orderContractId,
            'source_type' => PaymentDocument::class,
            'source_id' => $request->id,
            'currency' => 'RUB',
            'due_date' => now()->addDays(3)->toDateString(),
        ])->save();
        if ($orderStatus !== PaymentDocumentStatus::DRAFT) {
            $this->service()->syncReservation($existingOrder->fresh(), $context->user);
        }

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/payments/requests/{$request->id}/accept");
        if ($accepted) {
            $response->assertOk()->assertJsonPath('data.payment_order.id', $existingOrder->id);
        } else {
            $response->assertUnprocessable();
        }
        $this->assertDatabaseHas('budget_limit_reservations', [
            'payment_document_id' => $request->id,
            'status' => $accepted ? BudgetLimitReservation::STATUS_RELEASED : BudgetLimitReservation::STATUS_RESERVED,
        ]);
        self::assertSame($hasBudgetMismatch ? 1800.0 : 900.0, (float) BudgetLimitReservation::query()
            ->where('status', BudgetLimitReservation::STATUS_RESERVED)->sum('amount'));
        self::assertSame(2, PaymentDocument::query()->count());
        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public function test_coverage_query_loads_only_requests_in_the_calculation_month(): void
    {
        $this->travelTo(now()->startOfMonth()->addDays(10)->setTime(9, 0));
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activateBudgetingModule($context->organization->id);
        $budget = $this->createBudgetLine($context->organization->id, 1000.0);
        $contractor = Contractor::query()->create(['organization_id' => $context->organization->id, 'name' => 'Контрагент для проверки периода']);
        $requests = [];
        $orders = [];
        foreach ([true, false] as $historical) {
            $amount = $historical ? 900.0 : 100.0;
            $request = $this->createDocument($context, $budget, $amount);
            $request->forceFill([
                'document_type' => PaymentDocumentType::PAYMENT_REQUEST, 'status' => PaymentDocumentStatus::APPROVED,
                'currency' => 'RUB', 'payer_organization_id' => $context->organization->id, 'payee_contractor_id' => $contractor->id,
                'due_date' => $historical ? now()->subMonthNoOverflow()->toDateString() : now()->toDateString(),
            ])->save();
            $order = $this->createDocument($context, $budget, $amount);
            $order->forceFill([
                'document_type' => PaymentDocumentType::PAYMENT_ORDER,
                'status' => $historical ? PaymentDocumentStatus::PAID : PaymentDocumentStatus::APPROVED,
                'currency' => 'RUB', 'payer_organization_id' => $context->organization->id, 'payee_contractor_id' => $contractor->id,
                'source_type' => PaymentDocument::class, 'source_id' => $request->id,
                'paid_amount' => $historical ? $amount : 0, 'remaining_amount' => $historical ? 0 : $amount,
            ])->save();
            if (! $historical) {
                $this->service()->syncReservation($order->fresh(), $context->user);
            }
            $requests[] = $request;
            $orders[] = $order;
        }
        $probe = $this->createDocument($context, $budget, 50.0);
        $probe->forceFill(['currency' => 'RUB'])->save();
        $eventName = 'eloquent.retrieved: '.PaymentDocument::class;
        \Illuminate\Support\Facades\Event::fakeFor(function () use ($probe, $context, $orders, $requests, $eventName): void {
            self::assertSame('850.00', $this->service()->check($probe, $context->user)['summary']['available_after_request']);
            \Illuminate\Support\Facades\Event::assertDispatched($eventName, static function ($name, $payload) use ($orders): bool {
                $model = is_array($payload) ? ($payload[0] ?? null) : $payload;
                return $model instanceof PaymentDocument && $model->id === $orders[1]->id;
            });
            \Illuminate\Support\Facades\Event::assertNotDispatched($eventName, static function ($name, $payload) use ($orders, $requests): bool {
                $model = is_array($payload) ? ($payload[0] ?? null) : $payload;
                return $model instanceof PaymentDocument && in_array($model->id, [$orders[0]->id, $requests[0]->id], true);
            });
        }, [$eventName]);
    }

    public static function legacyRequestOrders(): array
    {
        return [
            'compatible' => [PaymentDocumentStatus::APPROVED, null, true],
            'compatible_draft' => [PaymentDocumentStatus::DRAFT, null, true],
            'canonical_counterparty' => [PaymentDocumentStatus::DRAFT, 'canonical_counterparty', true],
            'different_counterparty' => [PaymentDocumentStatus::DRAFT, 'counterparty', false],
            'missing_counterparty' => [PaymentDocumentStatus::DRAFT, 'missing_counterparty', false],
            'different_article' => [PaymentDocumentStatus::APPROVED, 'article', false],
            'different_center' => [PaymentDocumentStatus::APPROVED, 'center', false],
            'different_contract' => [PaymentDocumentStatus::APPROVED, 'contract', false],
            'missing_contract' => [PaymentDocumentStatus::APPROVED, 'missing_contract', false],
            'incoming_order' => [PaymentDocumentStatus::APPROVED, 'direction', false],
            'cancelled' => [PaymentDocumentStatus::CANCELLED, null, false],
            'rejected' => [PaymentDocumentStatus::REJECTED, null, false],
        ];
    }

    public function test_act_budget_limit_does_not_use_a_different_contracts_line(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activateBudgetingModule($context->organization->id);
        $budget = $this->createBudgetLine($context->organization->id, 100000.0);
        $act = $this->createApprovedAct($context);
        $otherAct = $this->createApprovedAct($context, $act->project_id);
        $budget['line']->forceFill(['project_id' => $act->project_id, 'contract_id' => $act->contract_id])->save();
        $document = $this->createDocument($context, $budget, 400.0);
        $document->forceFill([
            'project_id' => $act->project_id,
            'invoiceable_type' => ContractPerformanceAct::class,
            'invoiceable_id' => $otherAct->id,
            'metadata' => ['contract_id' => $act->contract_id],
        ])->save();

        $this->assertSame('block', $this->service()->check($document->fresh(), $context->user)['decision']);
    }

    public function test_act_contract_lookup_keeps_organization_and_project_scope(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activateBudgetingModule($context->organization->id);
        $budget = $this->createBudgetLine($context->organization->id, 100000.0);
        $act = $this->createApprovedAct($context);
        $budget['line']->forceFill(['contract_id' => $act->contract_id])->save();
        $foreignContext = AdminApiTestContext::create(roleSlug: 'web_admin');
        $foreignAct = $this->createApprovedAct($foreignContext);
        $otherProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $document = $this->createDocument($context, $budget, 400.0);

        foreach ([[$foreignAct, $act->project_id], [$act, $otherProject->id]] as [$basis, $projectId]) {
            $document->forceFill([
                'project_id' => $projectId,
                'invoiceable_type' => ContractPerformanceAct::class,
                'invoiceable_id' => $basis->id,
                'metadata' => ['contract_id' => $act->contract_id],
            ])->save();
            $this->assertSame('block', $this->service()->check($document->fresh(), $context->user)['decision']);
        }
    }

    public static function contractUsageCases(): array
    {
        return [['paid', 'actual_payments'], ['legacy', 'pending_approvals']];
    }

    #[DataProvider('contractUsageCases')]
    public function test_other_acts_of_the_same_contract_consume_its_budget(string $usage, string $source): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activateBudgetingModule($context->organization->id);
        $budget = $this->createBudgetLine($context->organization->id, 100000.0);
        $act = $this->createApprovedAct($context);
        $nextAct = ContractPerformanceAct::withoutEvents(static fn () => ContractPerformanceAct::query()->create([
            'contract_id' => $act->contract_id,
            'project_id' => $act->project_id,
            'act_document_number' => 'ACT-BUDGET-NEXT',
            'act_date' => now()->toDateString(),
            'amount' => '100000.00',
            'currency' => 'RUB',
            'status' => ContractPerformanceAct::STATUS_APPROVED,
            'is_approved' => true,
        ]));
        $budget['line']->forceFill([
            'project_id' => $act->project_id,
            'contract_id' => $act->contract_id,
            'metadata' => ['enforcement_mode' => 'hard_block'],
        ])->save();
        $first = $this->createDocument($context, $budget, 60000.0);
        $first->forceFill([
            'project_id' => $act->project_id,
            'invoiceable_type' => ContractPerformanceAct::class,
            'invoiceable_id' => $act->id,
            'status' => PaymentDocumentStatus::APPROVED,
        ])->save();
        if ($usage === 'paid') {
            $this->service()->syncReservation($first->fresh(), $context->user);
            $transaction = PaymentTransaction::query()->create([
                'payment_document_id' => $first->id,
                'organization_id' => $context->organization->id,
                'project_id' => $act->project_id,
                'amount' => 60000,
                'currency' => 'RUB',
                'payment_method' => PaymentMethod::BANK_TRANSFER,
                'transaction_date' => now()->toDateString(),
                'status' => PaymentTransactionStatus::COMPLETED,
                'created_by_user_id' => $context->user->id,
            ]);
            $first->forceFill(['status' => PaymentDocumentStatus::PAID, 'paid_amount' => 60000, 'remaining_amount' => 0])->save();
            $this->service()->convertAfterPayment($first->fresh(), $transaction);
            $this->assertDatabaseHas('budget_limit_reservations', ['payment_document_id' => $first->id, 'status' => BudgetLimitReservation::STATUS_CONVERTED]);
        }
        $next = $this->createDocument($context, $budget, 60000.0);
        $next->forceFill([
            'project_id' => $nextAct->project_id,
            'invoiceable_type' => ContractPerformanceAct::class,
            'invoiceable_id' => $nextAct->id,
        ])->save();

        $check = $this->service()->check($next->fresh(), $context->user);

        $this->assertSame('60000.00', $check['sources'][$source]);
        $this->assertSame('block', $check['decision']);
    }

    #[DataProvider('contractUsageCases')]
    public function test_act_budget_usage_keeps_other_scopes_and_months_outside_the_limit(string $usage, string $source): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activateBudgetingModule($context->organization->id);
        $budget = $this->createBudgetLine($context->organization->id, 100000.0);
        $act = $this->createApprovedAct($context);
        $otherAct = $this->createApprovedAct($context, $act->project_id);
        $foreignContext = AdminApiTestContext::create(roleSlug: 'web_admin');
        $otherProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $budget['line']->forceFill(['project_id' => $act->project_id, 'contract_id' => $act->contract_id])->save();
        $variants = [
            [$context, $otherAct, $act->project_id, now(), 'RUB', InvoiceDirection::OUTGOING],
            [$foreignContext, $act, $act->project_id, now(), 'RUB', InvoiceDirection::OUTGOING],
            [$context, $act, $otherProject->id, now(), 'RUB', InvoiceDirection::OUTGOING],
            [$context, $act, $act->project_id, now()->subMonthNoOverflow(), 'RUB', InvoiceDirection::OUTGOING],
            [$context, $act, $act->project_id, now(), 'USD', InvoiceDirection::OUTGOING],
            [$context, $act, $act->project_id, now(), 'RUB', InvoiceDirection::INCOMING],
        ];
        foreach ($variants as [$owner, $basis, $projectId, $date, $currency, $direction]) {
            $existing = $this->createDocument($owner, $budget, 60000.0);
            $existing->forceFill([
                'project_id' => $projectId,
                'invoiceable_type' => ContractPerformanceAct::class,
                'invoiceable_id' => $basis->id,
                'direction' => $direction,
                'currency' => $currency,
                'due_date' => $date->toDateString(),
                'status' => $usage === 'paid' ? PaymentDocumentStatus::PAID : PaymentDocumentStatus::APPROVED,
                'paid_amount' => $usage === 'paid' ? 60000 : 0,
                'remaining_amount' => $usage === 'paid' ? 0 : 60000,
            ])->save();
            if ($usage === 'paid') {
                PaymentTransaction::query()->create([
                    'payment_document_id' => $existing->id,
                    'organization_id' => $owner->organization->id,
                    'project_id' => $projectId,
                    'amount' => 60000,
                    'currency' => $currency,
                    'payment_method' => PaymentMethod::BANK_TRANSFER,
                    'transaction_date' => $date->toDateString(),
                    'status' => PaymentTransactionStatus::COMPLETED,
                    'created_by_user_id' => $owner->user->id,
                ]);
            }
        }
        $next = $this->createDocument($context, $budget, 60000.0);
        $next->forceFill([
            'project_id' => $act->project_id,
            'invoiceable_type' => ContractPerformanceAct::class,
            'invoiceable_id' => $act->id,
        ])->save();

        $check = $this->service()->check($next->fresh(), $context->user);

        $this->assertSame('0.00', $check['sources'][$source]);
        $this->assertContains($check['decision'], ['allow', 'warn']);
    }

    public function test_payment_registration_preview_uses_selected_month_without_writes(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activateBudgetingModule($context->organization->id);
        $budget = $this->createBudgetLine($context->organization->id, 1000.0);
        $document = $this->createDocument($context, $budget, 400.0);
        $document->forceFill(['scheduled_at' => now()->addMonthNoOverflow()->startOfMonth()])->save();

        $documentCheck = $this->service()->check($document->fresh(), $context->user);
        $paymentCheck = $this->service()->checkPaymentRegistration(
            $document->fresh(),
            '400.00',
            now(),
            $context->user
        );

        $this->assertSame('block', $documentCheck['decision']);
        $this->assertSame('allow', $paymentCheck['decision']);
        $this->assertSame(now()->format('Y-m'), $paymentCheck['dimensions']['period']);
        $this->assertDatabaseCount('budget_limit_checks', 0);
        $this->assertDatabaseCount('budget_limit_reservations', 0);
        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public function test_payment_budget_preview_endpoint_returns_selected_date_result_without_mutation(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $this->activateBudgetingModule($context->organization->id);
        $budget = $this->createBudgetLine($context->organization->id, 1000.0);
        $document = $this->createDocument($context, $budget, 400.0);
        $document->forceFill(['scheduled_at' => now()->addMonthNoOverflow()->startOfMonth()])->save();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/payments/documents/{$document->id}/payment-budget-preview", [
                'amount' => '400.00',
                'transaction_date' => now()->toDateString(),
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.decision', 'allow');
        $response->assertJsonPath('data.dimensions.period', now()->format('Y-m'));
        $this->assertDatabaseCount('budget_limit_checks', 0);
        $this->assertDatabaseCount('budget_limit_reservations', 0);
        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public function test_exceeded_limit_is_blocked_without_override_permission(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activateBudgetingModule($context->organization->id);
        $budget = $this->createBudgetLine($context->organization->id, 500.0);
        $document = $this->createDocument($context, $budget, 1000.0);
        $userWithoutPermission = User::factory()->create([
            'current_organization_id' => $context->organization->id,
        ]);

        $this->expectException(\App\BusinessModules\Core\Payments\Exceptions\PaymentBudgetLimitException::class);

        $this->service()->assertAllowed(
            $document,
            PaymentBudgetLimitService::OPERATION_SUBMIT,
            1000.0,
            $userWithoutPermission
        );
    }

    public function test_override_requires_permission_and_reason_and_writes_audit(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activateBudgetingModule($context->organization->id);
        $budget = $this->createBudgetLine($context->organization->id, 500.0);
        $document = $this->createDocument($context, $budget, 1000.0);

        $this->expectException(\DomainException::class);

        try {
            $this->service()->assertAllowed(
                $document,
                PaymentBudgetLimitService::OPERATION_SUBMIT,
                1000.0,
                $context->user
            );
        } finally {
            $this->service()->assertAllowed(
                $document,
                PaymentBudgetLimitService::OPERATION_SUBMIT,
                1000.0,
                $context->user,
                'Срочная поставка для непрерывности работ'
            );

            $this->assertDatabaseHas('budget_limit_checks', [
                'payment_document_id' => $document->id,
                'decision' => 'require_exception',
                'accepted' => true,
                'override_reason' => 'Срочная поставка для непрерывности работ',
            ]);
            $this->assertDatabaseHas('payment_audit_logs', [
                'payment_document_id' => $document->id,
                'action' => 'budget_limit_override',
            ]);

            $presentation = $this->service()->check($document->fresh(), $context->user);
            $this->assertTrue($presentation['override_applied']);
            $this->assertSame(
                'Срочная поставка для непрерывности работ',
                $presentation['override_reason']
            );
        }
    }

    public function test_reservation_is_adjusted_released_and_converted_after_payment(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activateBudgetingModule($context->organization->id);
        $budget = $this->createBudgetLine($context->organization->id, 1000.0);
        $document = $this->createDocument($context, $budget, 400.0);

        $this->service()->assertAllowed(
            $document,
            PaymentBudgetLimitService::OPERATION_SUBMIT,
            400.0,
            $context->user
        );
        $document->forceFill(['status' => PaymentDocumentStatus::SUBMITTED])->save();
        $this->service()->syncReservation($document->fresh(), $context->user);

        $this->assertDatabaseHas('budget_limit_reservations', [
            'payment_document_id' => $document->id,
            'status' => BudgetLimitReservation::STATUS_RESERVED,
            'amount' => 400.0,
        ]);

        $document->forceFill([
            'amount' => 300.0,
            'remaining_amount' => 300.0,
        ])->save();
        $this->service()->syncReservation($document->fresh(), $context->user);

        $this->assertDatabaseHas('budget_limit_reservations', [
            'payment_document_id' => $document->id,
            'status' => BudgetLimitReservation::STATUS_RESERVED,
            'amount' => 300.0,
        ]);

        $this->service()->release($document->fresh(), 'Отменено');

        $this->assertDatabaseHas('budget_limit_reservations', [
            'payment_document_id' => $document->id,
            'status' => BudgetLimitReservation::STATUS_RELEASED,
            'release_reason' => 'Отменено',
        ]);
        $reservation = BudgetLimitReservation::query()
            ->where('payment_document_id', $document->id)
            ->latest('id')
            ->firstOrFail();
        $versions = DB::table('budgeting_portfolio_liquidity_source_versions')
            ->where('source_type', 'budget_limit_reservation')
            ->where('source_id', (string) $reservation->id)
            ->orderBy('id')
            ->get();
        $this->assertGreaterThanOrEqual(3, $versions->count());
        $this->assertNull($versions->last()->payload);

        $document->forceFill(['status' => PaymentDocumentStatus::APPROVED])->save();
        $this->service()->syncReservation($document->fresh(), $context->user);
        $transaction = PaymentTransaction::query()->create([
            'payment_document_id' => $document->id,
            'organization_id' => $context->organization->id,
            'project_id' => $document->project_id,
            'amount' => 300.0,
            'currency' => 'RUB',
            'payment_method' => PaymentMethod::BANK_TRANSFER,
            'transaction_date' => now()->toDateString(),
            'status' => PaymentTransactionStatus::COMPLETED,
            'created_by_user_id' => $context->user->id,
        ]);
        $document->forceFill([
            'status' => PaymentDocumentStatus::PAID,
            'paid_amount' => 300.0,
            'remaining_amount' => 0.0,
        ])->save();

        $this->service()->convertAfterPayment($document->fresh(), $transaction);

        $this->assertDatabaseHas('budget_limit_reservations', [
            'payment_document_id' => $document->id,
            'status' => BudgetLimitReservation::STATUS_CONVERTED,
            'amount' => 0.0,
        ]);

        $refund = PaymentTransaction::query()->create([
            'payment_document_id' => $document->id,
            'organization_id' => $context->organization->id,
            'project_id' => $document->project_id,
            'amount' => -100.0,
            'currency' => 'RUB',
            'payment_method' => PaymentMethod::BANK_TRANSFER,
            'transaction_date' => now()->toDateString(),
            'status' => PaymentTransactionStatus::COMPLETED,
            'reverses_transaction_id' => $transaction->id,
            'created_by_user_id' => $context->user->id,
        ]);
        $document->forceFill([
            'status' => PaymentDocumentStatus::PARTIALLY_PAID,
            'paid_amount' => 200.0,
            'remaining_amount' => 100.0,
        ])->save();

        $this->service()->reconcileAfterLedgerChange($document->fresh(), $refund);

        $this->assertDatabaseHas('budget_limit_reservations', [
            'payment_document_id' => $document->id,
            'status' => BudgetLimitReservation::STATUS_RESERVED,
            'amount' => 100.0,
        ]);
    }

    public function test_budgeting_inactive_does_not_block_payment_lifecycle(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $document = $this->createDocument($context, null, 5000.0);

        $result = $this->service()->assertAllowed(
            $document,
            PaymentBudgetLimitService::OPERATION_SUBMIT,
            5000.0,
            $context->user
        );

        $this->assertNull($result);
        $this->assertDatabaseMissing('budget_limit_checks', [
            'payment_document_id' => $document->id,
        ]);
    }

    private function createApprovedAct(AdminApiTestContext $context, ?int $projectId = null): ContractPerformanceAct
    {
        $projectId ??= Project::factory()->create(['organization_id' => $context->organization->id])->id;
        $contractor = Contractor::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Учебный подрядчик по акту',
        ]);
        $contract = Contract::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $projectId,
            'contractor_id' => $contractor->id,
            'number' => 'ACT-BUDGET-'.uniqid(),
            'date' => now()->toDateString(),
            'status' => 'active',
            'total_amount' => 100000,
        ]);

        return ContractPerformanceAct::withoutEvents(static fn () => ContractPerformanceAct::query()->create([
            'contract_id' => $contract->id,
            'project_id' => $projectId,
            'act_document_number' => 'ACT-BUDGET-'.uniqid(),
            'act_date' => now()->toDateString(),
            'amount' => '100000.00',
            'currency' => 'RUB',
            'status' => ContractPerformanceAct::STATUS_APPROVED,
            'is_approved' => true,
        ]));
    }

    private function service(): PaymentBudgetLimitService
    {
        return app(PaymentBudgetLimitService::class);
    }

    /**
     * @param  array{article: BudgetArticle, center: ResponsibilityCenter, line: BudgetLine}|null  $budget
     */
    private function createDocument(AdminApiTestContext $context, ?array $budget, float $amount): PaymentDocument
    {
        return PaymentDocument::query()->create([
            'organization_id' => $context->organization->id,
            'budget_article_id' => $budget['article']->id ?? null,
            'responsibility_center_id' => $budget['center']->id ?? null,
            'document_type' => PaymentDocumentType::INVOICE,
            'document_number' => 'LIMIT-'.uniqid(),
            'document_date' => now()->toDateString(),
            'direction' => InvoiceDirection::OUTGOING,
            'amount' => $amount,
            'paid_amount' => 0,
            'remaining_amount' => $amount,
            'status' => PaymentDocumentStatus::DRAFT,
            'due_date' => now()->toDateString(),
            'created_by_user_id' => $context->user->id,
        ]);
    }

    private function activateBudgetingModule(int $organizationId): void
    {
        $module = Module::query()->firstOrCreate(
            ['slug' => 'budgeting'],
            [
                'name' => 'Budgeting',
                'version' => '1.0.0',
                'type' => 'feature',
                'billing_model' => 'free',
                'category' => 'finance',
                'permissions' => ['budgeting.limits.override'],
                'is_active' => true,
                'is_system_module' => false,
            ]
        );
        $module->forceFill(['is_system_module' => true])->save();

        OrganizationModuleActivation::query()->create([
            'organization_id' => $organizationId,
            'module_id' => $module->id,
            'status' => 'active',
            'activated_at' => now(),
        ]);
        app(ModulePermissionChecker::class)->clearModuleCache($organizationId, 'budgeting');
    }

    private function activatePaymentsModule(int $organizationId): void
    {
        $module = Module::query()->firstOrCreate(
            ['slug' => 'payments'],
            [
                'name' => 'Payments',
                'version' => '1.0.0',
                'type' => 'core',
                'billing_model' => 'free',
                'category' => 'finance',
                'permissions' => [
                    'payments.invoice.edit',
                    'payments.transaction.register',
                ],
                'is_active' => true,
                'is_system_module' => false,
            ]
        );

        OrganizationModuleActivation::query()->create([
            'organization_id' => $organizationId,
            'module_id' => $module->id,
            'status' => 'active',
            'activated_at' => now(),
        ]);
        app(ModulePermissionChecker::class)->clearModuleCache($organizationId, 'payments');
    }

    /**
     * @return array{article: BudgetArticle, center: ResponsibilityCenter, line: BudgetLine}
     */
    private function createBudgetLine(int $organizationId, float $planAmount): array
    {
        $period = BudgetPeriod::query()->create([
            'organization_id' => $organizationId,
            'code' => 'PER-'.uniqid(),
            'name' => 'Текущий месяц',
            'period_type' => 'month',
            'starts_at' => now()->startOfMonth()->toDateString(),
            'ends_at' => now()->endOfMonth()->toDateString(),
            'status' => 'open',
        ]);

        $scenario = BudgetScenario::query()->create([
            'organization_id' => $organizationId,
            'code' => 'BASE-'.uniqid(),
            'name' => 'Базовый',
            'scenario_type' => 'base',
            'is_default' => true,
            'is_active' => true,
        ]);

        $article = BudgetArticle::query()->create([
            'organization_id' => $organizationId,
            'code' => 'PAY-'.uniqid(),
            'name' => 'Платежи подрядчикам',
            'budget_kind' => 'bdds',
            'flow_direction' => 'outflow',
            'is_leaf' => true,
            'is_active' => true,
        ]);

        $center = ResponsibilityCenter::query()->create([
            'organization_id' => $organizationId,
            'center_type' => 'project',
            'code' => 'CFO-'.uniqid(),
            'name' => 'ЦФО проекта',
            'is_active' => true,
        ]);

        $version = BudgetVersion::query()->create([
            'organization_id' => $organizationId,
            'budget_period_id' => $period->id,
            'scenario_id' => $scenario->id,
            'budget_kind' => 'bdds',
            'version_number' => 1,
            'name' => 'Активный бюджет',
            'status' => 'active',
            'approved_at' => now(),
            'activated_at' => now(),
        ]);

        $line = BudgetLine::query()->create([
            'budget_version_id' => $version->id,
            'budget_article_id' => $article->id,
            'responsibility_center_id' => $center->id,
            'currency' => 'RUB',
        ]);

        BudgetAmount::query()->create([
            'budget_line_id' => $line->id,
            'month' => now()->startOfMonth()->toDateString(),
            'plan_amount' => $planAmount,
            'forecast_amount' => $planAmount,
            'currency' => 'RUB',
        ]);

        return [
            'article' => $article,
            'center' => $center,
            'line' => $line,
        ];
    }
}
