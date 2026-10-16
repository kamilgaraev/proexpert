<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessModules\Core\Payments;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Models\CounterpartyAccount;
use App\BusinessModules\Core\Payments\Models\PaymentAuditLog;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Contractor;
use App\Models\Module;
use App\Models\OrganizationModuleActivation;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class PaymentRequestControllerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_request_can_be_created_even_when_counterparty_account_exists(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $contractor = $this->createContractor($context);

        CounterpartyAccount::query()->create([
            'organization_id' => $context->organization->id,
            'counterparty_contractor_id' => $contractor->id,
            'receivable_balance' => 0,
            'payable_balance' => 0,
            'net_balance' => 0,
            'is_active' => true,
            'is_blocked' => false,
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/payments/requests', [
                'contractor_id' => $contractor->id,
                'amount' => 1500,
                'currency' => 'RUB',
                'payment_purpose' => 'Оплата работ по акту',
                'bank_account' => '40702810000000000001',
                'bank_bik' => '044525225',
                'bank_correspondent_account' => '30101810400000000225',
                'bank_name' => 'Банк',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.contractor.id', $contractor->id);
        $response->assertJsonPath('data.amount', 1500);

        $this->assertDatabaseHas('payment_documents', [
            'organization_id' => $context->organization->id,
            'payee_contractor_id' => $contractor->id,
            'document_type' => PaymentDocumentType::PAYMENT_REQUEST->value,
            'payment_purpose' => 'Оплата работ по акту',
        ]);
    }

    public function test_approved_payment_request_can_be_accepted_into_payment_order(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $contractor = $this->createContractor($context);
        $request = $this->createPaymentRequest($context, $contractor, PaymentDocumentStatus::APPROVED);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/payments/requests/{$request->id}/accept", [
                'scheduled_at' => now()->addDays(3)->toDateString(),
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.request.id', $request->id);
        $response->assertJsonPath('data.payment_order.status', PaymentDocumentStatus::DRAFT->value);
        self::assertSame(now()->addDays(3)->toDateString(), PaymentDocument::findOrFail($response->json('data.payment_order.id'))->scheduled_at->toDateString());

        $this->assertDatabaseHas('payment_documents', [
            'source_id' => $request->id,
            'document_type' => PaymentDocumentType::PAYMENT_ORDER->value,
            'payee_contractor_id' => $contractor->id,
            'amount' => '900.00',
        ]);

        $retry = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/payments/requests/{$request->id}/accept");

        $retry->assertOk();
        $retry->assertJsonPath('data.payment_order.id', $response->json('data.payment_order.id'));
        self::assertSame(1, PaymentDocument::query()
            ->where('organization_id', $context->organization->id)
            ->where('source_type', PaymentDocument::class)
            ->where('source_id', $request->id)
            ->where('document_type', PaymentDocumentType::PAYMENT_ORDER->value)
            ->count());
        self::assertSame(PaymentDocumentStatus::APPROVED, $request->fresh()->status);
    }

    public function test_generic_creation_and_request_acceptance_reuse_the_same_order(): void
    {
        foreach ([true, false] as $acceptFirst) {
            $context = AdminApiTestContext::create(roleSlug: 'web_admin');
            $this->activatePaymentsModule($context->organization->id);
            $contractor = $this->createContractor($context);
            $request = $this->createPaymentRequest($context, $contractor, PaymentDocumentStatus::APPROVED);
            $payload = [
                'document_type' => PaymentDocumentType::PAYMENT_ORDER->value,
                'document_date' => now()->toDateString(),
                'due_date' => $request->due_date->toDateString(),
                'amount' => 900,
                'currency' => 'RUB',
                'payer_organization_id' => $context->organization->id,
                'payee_contractor_id' => $contractor->id,
                'source_type' => PaymentDocument::class,
                'source_id' => $request->id,
            ];
            if (! $acceptFirst) {
                $request->update(['currency' => 'USD']);
                unset($payload['currency']);
            }

            if ($acceptFirst) {
                $accepted = $this->withHeaders($context->authHeaders())
                    ->postJson("/api/v1/admin/payments/requests/{$request->id}/accept")
                    ->assertOk();
            }
            $created = $this->withHeaders($context->authHeaders())
                ->postJson('/api/v1/admin/payments/documents', $payload)->assertCreated();
            if (! $acceptFirst) {
                $accepted = $this->withHeaders($context->authHeaders())
                    ->postJson("/api/v1/admin/payments/requests/{$request->id}/accept")
                    ->assertOk();
            }

            self::assertSame($accepted->json('data.payment_order.id'), $created->json('data.id'));
            self::assertSame(1, PaymentDocument::query()->forOrganization($context->organization->id)
                ->where('source_type', PaymentDocument::class)->where('source_id', $request->id)->count());
            $this->assertDatabaseHas('payment_documents', [
                'id' => $created->json('data.id'),
                'origin_key' => "payment-request:{$request->id}:order",
                'currency' => $request->currency,
            ]);
        }
    }

    public function test_generic_request_order_inherits_bank_details_and_missing_payment_terms(): void
    {
        foreach (['omitted', 'matching', 'explicit_terms'] as $scenario) {
            $context = AdminApiTestContext::create(roleSlug: 'web_admin');
            $this->activatePaymentsModule($context->organization->id);
            $contractor = $this->createContractor($context);
            $request = $this->createPaymentRequest($context, $contractor, PaymentDocumentStatus::APPROVED);
            $banks = [
                'bank_account' => '40702810000000000001',
                'bank_bik' => '044525225',
                'bank_correspondent_account' => '30101810400000000225',
                'bank_name' => 'Банк требования',
            ];
            $request->update($banks);
            $payload = [
                'document_type' => PaymentDocumentType::PAYMENT_ORDER->value,
                'document_date' => now()->toDateString(),
                'amount' => 900,
                'payer_organization_id' => $context->organization->id,
                'payee_contractor_id' => $contractor->id,
                'source_type' => PaymentDocument::class,
                'source_id' => $request->id,
            ];
            if ($scenario === 'matching') {
                $payload += $banks;
            }
            if ($scenario === 'explicit_terms') {
                $payload += [
                    'due_date' => now()->addDays(9)->toDateString(),
                    'payment_purpose' => 'Уточнённое назначение поручения',
                ];
            }

            $created = $this->withHeaders($context->authHeaders())
                ->postJson('/api/v1/admin/payments/documents', $payload)->assertCreated();
            $order = PaymentDocument::findOrFail($created->json('data.id'));
            foreach ($banks as $field => $value) {
                self::assertSame($value, $order->{$field}, $scenario.':'.$field);
            }
            self::assertSame($payload['due_date'] ?? $request->due_date->toDateString(), $order->due_date->toDateString());
            self::assertSame($payload['payment_purpose'] ?? $request->payment_purpose, $order->payment_purpose);
            $this->withHeaders($context->authHeaders())
                ->postJson("/api/v1/admin/payments/requests/{$request->id}/accept")
                ->assertOk()->assertJsonPath('data.payment_order.id', $order->id);
            self::assertSame(1, PaymentDocument::query()->where('source_type', PaymentDocument::class)
                ->where('source_id', $request->id)->count());
            if ($scenario === 'omitted') {
                $this->withHeaders($context->authHeaders())
                    ->putJson("/api/v1/admin/payments/documents/{$order->id}", ['bank_account' => '40702810000000000002'])
                    ->assertUnprocessable();
                self::assertSame($banks['bank_account'], $order->fresh()->bank_account);
            }
        }
        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public function test_draft_request_order_can_complete_missing_banks_without_changing_source_and_replay(): void
    {
        foreach ([false, true] as $partialSource) {
            $context = AdminApiTestContext::create(roleSlug: 'web_admin');
            $this->activatePaymentsModule($context->organization->id);
            $contractor = $this->createContractor($context);
            $request = $this->createPaymentRequest($context, $contractor, PaymentDocumentStatus::APPROVED);
            if ($partialSource) {
                $request->update(['bank_name' => 'Учебный банк']);
            }
            $sourceBanks = $request->only(['bank_account', 'bank_bik', 'bank_correspondent_account', 'bank_name']);
            $created = $this->withHeaders($context->authHeaders())
                ->postJson("/api/v1/admin/payments/requests/{$request->id}/accept")->assertOk();
            $order = PaymentDocument::findOrFail($created->json('data.payment_order.id'));
            $banks = [
                'bank_account' => '00000000000000000000',
                'bank_bik' => '000000000',
                'bank_name' => $partialSource ? 'Учебный банк' : 'Учебные реквизиты — не для оплаты',
            ];

            $this->withHeaders($context->authHeaders())
                ->putJson("/api/v1/admin/payments/documents/{$order->id}", $banks)->assertOk();
            $this->withHeaders($context->authHeaders())
                ->putJson("/api/v1/admin/payments/documents/{$order->id}", $banks)->assertOk();
            $this->withHeaders($context->authHeaders())
                ->postJson("/api/v1/admin/payments/requests/{$request->id}/accept")
                ->assertOk()->assertJsonPath('data.payment_order.id', $order->id);
            $this->withHeaders($context->authHeaders())
                ->putJson("/api/v1/admin/payments/documents/{$order->id}", ['bank_account' => '00000000000000000001'])
                ->assertUnprocessable();
            foreach ($banks as $field => $value) {
                self::assertSame($value, $order->fresh()->{$field});
            }
            self::assertSame($sourceBanks, $request->fresh()->only(array_keys($sourceBanks)));
            self::assertSame(PaymentDocumentStatus::APPROVED, $request->fresh()->status);
            self::assertSame('0.00', $order->fresh()->paid_amount);
            self::assertSame(1, PaymentDocument::query()->where('source_type', PaymentDocument::class)
                ->where('source_id', $request->id)->count());
            $this->withHeaders($context->authHeaders())
                ->postJson("/api/v1/admin/payments/documents/{$order->id}/submit")->assertOk();
            self::assertNotSame(PaymentDocumentStatus::DRAFT, $order->fresh()->status);
        }
        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public function test_legacy_banks_without_controlled_completion_cannot_be_reused_updated_or_submitted(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $contractor = $this->createContractor($context);
        $request = $this->createPaymentRequest($context, $contractor, PaymentDocumentStatus::APPROVED);
        $legacy = $request->replicate();
        $legacy->fill([
            'document_number' => 'LEGACY-'.uniqid(), 'document_type' => PaymentDocumentType::PAYMENT_ORDER,
            'status' => PaymentDocumentStatus::DRAFT, 'source_type' => PaymentDocument::class, 'source_id' => $request->id,
            'bank_account' => '00000000000000000000', 'bank_bik' => '000000000',
            'metadata' => ['request_order_bank_completion' => ['request_id' => $request->id, 'fingerprint' => 'forged']],
        ])->save();

        $this->withHeaders($context->authHeaders())->postJson("/api/v1/admin/payments/requests/{$request->id}/accept")
            ->assertUnprocessable();
        $this->withHeaders($context->authHeaders())->putJson("/api/v1/admin/payments/documents/{$legacy->id}", ['notes' => 'Changed'])
            ->assertUnprocessable();
        $this->withHeaders($context->authHeaders())->postJson("/api/v1/admin/payments/documents/{$legacy->id}/submit")
            ->assertUnprocessable();
        self::assertNull($legacy->fresh()->notes);
        self::assertSame(PaymentDocumentStatus::DRAFT, $legacy->fresh()->status);
        self::assertNull($request->fresh()->bank_account);
        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public function test_bank_completion_rolls_back_when_its_provenance_cannot_be_written(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $contractor = $this->createContractor($context);
        $request = $this->createPaymentRequest($context, $contractor, PaymentDocumentStatus::APPROVED);
        $created = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/payments/requests/{$request->id}/accept")->assertOk();
        $order = PaymentDocument::findOrFail($created->json('data.payment_order.id'));
        $dispatcher = PaymentAuditLog::getEventDispatcher();
        PaymentAuditLog::setEventDispatcher(clone $dispatcher);
        try {
            PaymentAuditLog::creating(static function (PaymentAuditLog $log): void {
                if (isset($log->metadata['request_order_bank_completion'])) {
                    throw new \RuntimeException('controlled_bank_completion_audit_unavailable');
                }
            });
            $this->withHeaders($context->authHeaders())
                ->putJson("/api/v1/admin/payments/documents/{$order->id}", [
                    'bank_account' => '00000000000000000000', 'bank_bik' => '000000000',
                ])->assertStatus(500);
        } finally {
            PaymentAuditLog::setEventDispatcher($dispatcher);
        }

        self::assertNull($order->fresh()->bank_account);
        self::assertNull($order->fresh()->bank_bik);
        self::assertSame(PaymentDocumentStatus::DRAFT, $order->fresh()->status);
        self::assertNull($request->fresh()->bank_account);
        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public function test_generic_request_order_cannot_replace_approved_bank_details(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $contractor = $this->createContractor($context);
        $request = $this->createPaymentRequest($context, $contractor, PaymentDocumentStatus::APPROVED);
        $request->update([
            'bank_account' => '40702810000000000001',
            'bank_bik' => '044525225',
            'bank_correspondent_account' => '30101810400000000225',
            'bank_name' => 'Банк требования',
        ]);
        $payload = [
            'document_type' => PaymentDocumentType::PAYMENT_ORDER->value,
            'document_date' => now()->toDateString(),
            'amount' => 900,
            'payer_organization_id' => $context->organization->id,
            'payee_contractor_id' => $contractor->id,
            'source_type' => PaymentDocument::class,
            'source_id' => $request->id,
        ];
        foreach ([
            ['bank_account' => '40702810000000000002'],
            ['bank_bik' => '044525226'],
            ['bank_correspondent_account' => '30101810400000000226'],
            ['bank_name' => 'Другой банк'],
            ['bank_account' => null],
        ] as $changes) {
            $this->withHeaders($context->authHeaders())
                ->postJson('/api/v1/admin/payments/documents', $payload + $changes)->assertUnprocessable();
            self::assertSame(0, PaymentDocument::query()->where('source_type', PaymentDocument::class)
                ->where('source_id', $request->id)->count());
        }
        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public function test_legacy_request_order_with_different_bank_details_cannot_be_reused_or_submitted(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $contractor = $this->createContractor($context);
        $banks = [
            'bank_account' => '40702810000000000001',
            'bank_bik' => '044525225',
            'bank_correspondent_account' => '30101810400000000225',
            'bank_name' => 'Банк требования',
        ];
        foreach ([
            ['bank_account' => '40702810000000000002'],
            ['bank_bik' => '044525226'],
            ['bank_correspondent_account' => '30101810400000000226'],
            ['bank_name' => 'Другой банк'],
            ['bank_account' => null],
        ] as $changes) {
            $request = $this->createPaymentRequest($context, $contractor, PaymentDocumentStatus::APPROVED);
            $request->update($banks);
            $legacy = $this->createPaymentRequest($context, $contractor, PaymentDocumentStatus::DRAFT);
            $legacy->update(array_replace($banks, $changes, [
                'document_type' => PaymentDocumentType::PAYMENT_ORDER,
                'source_type' => PaymentDocument::class,
                'source_id' => $request->id,
                'description' => 'Legacy bank details',
            ]));
            $this->withHeaders($context->authHeaders())
                ->postJson('/api/v1/admin/payments/documents', [
                    'document_type' => PaymentDocumentType::PAYMENT_ORDER->value,
                    'document_date' => now()->toDateString(), 'amount' => 900,
                    'payer_organization_id' => $context->organization->id,
                    'payee_contractor_id' => $contractor->id,
                    'source_type' => PaymentDocument::class, 'source_id' => $request->id,
                ])->assertUnprocessable();
            $this->withHeaders($context->authHeaders())
                ->postJson("/api/v1/admin/payments/requests/{$request->id}/accept")->assertUnprocessable();
            $this->withHeaders($context->authHeaders())
                ->putJson("/api/v1/admin/payments/documents/{$legacy->id}", ['description' => 'Changed'])
                ->assertUnprocessable();
            $this->withHeaders($context->authHeaders())
                ->postJson("/api/v1/admin/payments/documents/{$legacy->id}/submit")->assertUnprocessable();
            self::assertSame(PaymentDocumentStatus::DRAFT, $legacy->fresh()->status);
            self::assertSame('Legacy bank details', $legacy->fresh()->description);
            foreach ($changes as $field => $value) {
                self::assertSame($value, $legacy->fresh()->{$field});
            }
            self::assertSame(PaymentDocumentStatus::APPROVED, $request->fresh()->status);
            self::assertSame(1, PaymentDocument::query()->where('source_type', PaymentDocument::class)
                ->where('source_id', $request->id)->count());
        }
        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public function test_linked_request_order_cannot_change_approved_vat_rate(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $contractor = $this->createContractor($context);
        $request = $this->createPaymentRequest($context, $contractor, PaymentDocumentStatus::APPROVED);
        $request->update(['vat_rate' => 20]);
        $accepted = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/payments/requests/{$request->id}/accept")->assertOk();
        $order = PaymentDocument::findOrFail($accepted->json('data.payment_order.id'));
        self::assertSame('20.00', $order->vat_rate);
        $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/payments/documents/{$order->id}", ['vat_rate' => 0])
            ->assertUnprocessable();
        $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/payments/documents/{$order->id}", ['vat_rate' => null])
            ->assertUnprocessable();
        $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/payments/documents/{$order->id}", ['vat_rate' => '20'])
            ->assertOk();
        self::assertSame('20.00', $order->fresh()->vat_rate);
        self::assertSame('750.00', $order->fresh()->amount_without_vat);
        self::assertSame('150.00', $order->fresh()->vat_amount);
        $payload = [
            'document_type' => PaymentDocumentType::PAYMENT_ORDER->value,
            'document_date' => now()->toDateString(), 'amount' => 900,
            'payer_organization_id' => $context->organization->id, 'payee_contractor_id' => $contractor->id,
            'source_type' => PaymentDocument::class, 'source_id' => $request->id,
        ];
        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/payments/documents', $payload + ['vat_rate' => 0])->assertUnprocessable();
        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/payments/documents', $payload + ['vat_rate' => '20.00'])
            ->assertCreated()->assertJsonPath('data.id', $order->id);
        self::assertSame(1, PaymentDocument::query()->where('source_type', PaymentDocument::class)
            ->where('source_id', $request->id)->count());
        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public function test_legacy_request_order_with_different_vat_cannot_be_reused_or_submitted(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $contractor = $this->createContractor($context);
        $request = $this->createPaymentRequest($context, $contractor, PaymentDocumentStatus::APPROVED);
        $request->update(['vat_rate' => 20]);
        $legacy = $this->createPaymentRequest($context, $contractor, PaymentDocumentStatus::DRAFT);
        $legacy->update([
            'document_type' => PaymentDocumentType::PAYMENT_ORDER,
            'source_type' => PaymentDocument::class, 'source_id' => $request->id,
            'vat_rate' => 0, 'description' => 'Legacy VAT mismatch',
        ]);
        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/payments/documents', [
                'document_type' => PaymentDocumentType::PAYMENT_ORDER->value,
                'document_date' => now()->toDateString(), 'amount' => 900,
                'payer_organization_id' => $context->organization->id, 'payee_contractor_id' => $contractor->id,
                'source_type' => PaymentDocument::class, 'source_id' => $request->id,
            ])->assertUnprocessable();
        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/payments/requests/{$request->id}/accept")->assertUnprocessable();
        $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/payments/documents/{$legacy->id}", ['description' => 'Changed'])
            ->assertUnprocessable();
        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/payments/documents/{$legacy->id}/submit")->assertUnprocessable();
        self::assertSame('0.00', $legacy->fresh()->vat_rate);
        self::assertSame('Legacy VAT mismatch', $legacy->fresh()->description);
        self::assertSame(PaymentDocumentStatus::DRAFT, $legacy->fresh()->status);
        self::assertSame(PaymentDocumentStatus::APPROVED, $request->fresh()->status);
        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public function test_generic_order_creation_cannot_bypass_request_approval_or_change_its_obligation(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $contractor = $this->createContractor($context);
        $request = $this->createPaymentRequest($context, $contractor, PaymentDocumentStatus::PENDING_APPROVAL);
        $payload = [
            'document_type' => PaymentDocumentType::PAYMENT_ORDER->value,
            'document_date' => now()->toDateString(),
            'due_date' => $request->due_date->toDateString(),
            'amount' => 900,
            'currency' => 'RUB',
            'payer_organization_id' => $context->organization->id,
            'payee_contractor_id' => $contractor->id,
            'source_type' => PaymentDocument::class,
            'source_id' => $request->id,
        ];
        $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/payments/documents', $payload)
            ->assertUnprocessable();
        $request->update(['status' => PaymentDocumentStatus::APPROVED]);
        $otherContractor = Contractor::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Other Request Contractor',
            'inn' => '7700000020',
        ]);
        foreach ([['amount' => 450], ['currency' => 'USD'], ['payee_contractor_id' => $otherContractor->id], ['direction' => 'incoming']] as $changes) {
            $this->withHeaders($context->authHeaders())
                ->postJson('/api/v1/admin/payments/documents', array_replace($payload, $changes))
                ->assertUnprocessable();
        }
        self::assertSame(0, PaymentDocument::query()->where('source_id', $request->id)->count());
        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public function test_linked_request_orders_require_approval_permission_for_every_write_path(): void
    {
        $context = AdminApiTestContext::create();
        $this->activatePaymentsModule($context->organization->id);
        $this->assignPaymentPermissions($context, [
            'payments.invoice.view', 'payments.invoice.create', 'payments.invoice.edit', 'payments.invoice.issue',
        ]);
        self::assertFalse(app(AuthorizationService::class)->canCurrent($context->user, 'payments.transaction.approve', [
            'organization_id' => $context->organization->id,
        ]));
        $contractor = $this->createContractor($context);
        $request = $this->createPaymentRequest($context, $contractor, PaymentDocumentStatus::APPROVED);
        $payload = [
            'document_type' => PaymentDocumentType::PAYMENT_ORDER->value,
            'document_date' => now()->toDateString(),
            'due_date' => $request->due_date->toDateString(),
            'amount' => 900,
            'currency' => 'RUB',
            'payer_organization_id' => $context->organization->id,
            'payee_contractor_id' => $contractor->id,
        ];
        $ordinary = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/payments/documents', $payload)->assertCreated();
        $this->withHeaders($context->authHeaders())
            ->putJson('/api/v1/admin/payments/documents/'.$ordinary->json('data.id'), ['description' => 'Allowed ordinary edit'])
            ->assertOk();

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/payments/documents', $payload + [
                'source_type' => PaymentDocument::class, 'source_id' => $request->id,
            ])->assertForbidden();
        self::assertSame(0, PaymentDocument::query()->where('source_type', PaymentDocument::class)
            ->where('source_id', $request->id)->count());

        $legacy = $this->createPaymentRequest($context, $contractor, PaymentDocumentStatus::DRAFT);
        $legacy->update([
            'document_type' => PaymentDocumentType::PAYMENT_ORDER,
            'source_type' => PaymentDocument::class, 'source_id' => $request->id,
            'description' => 'Legacy draft',
        ]);
        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/payments/documents', $payload + [
                'source_type' => PaymentDocument::class, 'source_id' => $request->id,
            ])->assertForbidden();
        $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/payments/documents/{$legacy->id}", ['description' => 'Forbidden linked edit'])
            ->assertForbidden();
        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/payments/documents/{$legacy->id}/submit")
            ->assertForbidden();
        self::assertSame(PaymentDocumentStatus::DRAFT, $legacy->fresh()->status);
        self::assertSame('Legacy draft', $legacy->fresh()->description);
        self::assertSame(PaymentDocumentStatus::APPROVED, $request->fresh()->status);
        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public function test_generic_acceptance_honors_the_approval_project_scope(): void
    {
        $context = AdminApiTestContext::create();
        $this->activatePaymentsModule($context->organization->id);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $otherProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $context->organization->users()->updateExistingPivot($context->user->id, ['project_access_mode' => 'all_projects']);
        $this->assignPaymentPermissions($context, ['payments.invoice.create']);
        $this->assignPaymentPermissions($context, ['payments.transaction.approve'], $project->id, false);
        $contractor = $this->createContractor($context);

        foreach ([$otherProject, $project] as $sourceProject) {
            $request = $this->createPaymentRequest($context, $contractor, PaymentDocumentStatus::APPROVED);
            $request->update(['project_id' => $sourceProject->id]);
            $response = $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/payments/documents', [
                'document_type' => PaymentDocumentType::PAYMENT_ORDER->value,
                'document_date' => now()->toDateString(), 'amount' => 900, 'currency' => 'RUB',
                'project_id' => $sourceProject->id,
                'payer_organization_id' => $context->organization->id,
                'payee_contractor_id' => $contractor->id,
                'source_type' => PaymentDocument::class, 'source_id' => $request->id,
            ]);
            if ($sourceProject->is($otherProject)) {
                $response->assertForbidden();
                self::assertSame(0, PaymentDocument::query()->where('source_type', PaymentDocument::class)
                    ->where('source_id', $request->id)->count());
            } else {
                $response->assertCreated()->assertJsonPath('data.project.id', $project->id);
            }
        }
    }

    public function test_project_scoped_creator_can_inherit_the_request_project_without_changing_explicit_scope(): void
    {
        $context = AdminApiTestContext::create();
        $this->activatePaymentsModule($context->organization->id);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $otherProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $context->organization->users()->updateExistingPivot($context->user->id, ['project_access_mode' => 'all_projects']);
        $this->assignPaymentPermissions($context, []);
        $this->assignPaymentPermissions($context, ['payments.invoice.create', 'payments.transaction.approve'], $project->id, false);
        self::assertTrue(app(AuthorizationService::class)->canCurrent($context->user, 'admin.access', [
            'organization_id' => $context->organization->id,
        ]));
        self::assertFalse(app(AuthorizationService::class)->canCurrent($context->user, 'payments.invoice.create', [
            'organization_id' => $context->organization->id,
        ]));
        self::assertTrue(app(AuthorizationService::class)->canCurrent($context->user, 'payments.invoice.create', [
            'organization_id' => $context->organization->id, 'project_id' => $project->id, 'strict_project_scope' => true,
        ]));
        self::assertTrue(app(AuthorizationService::class)->canCurrent($context->user, 'payments.transaction.approve', [
            'organization_id' => $context->organization->id, 'project_id' => $project->id, 'strict_project_scope' => true,
        ]));
        $contractor = $this->createContractor($context);
        $foreign = AdminApiTestContext::create();
        $foreignRequest = $this->createPaymentRequest($foreign, $this->createContractor($foreign), PaymentDocumentStatus::APPROVED);

        foreach (['omitted', 'matching', 'explicit_null', 'conflicting', 'other_source_project', 'foreign_organization', 'projectless_source', 'non_request_source'] as $case) {
            $source = $this->createPaymentRequest($context, $contractor, PaymentDocumentStatus::APPROVED);
            $source->update(['project_id' => $project->id]);
            if ($case === 'other_source_project') {
                $source->update(['project_id' => $otherProject->id]);
            } elseif ($case === 'foreign_organization') {
                $source = $foreignRequest;
            } elseif ($case === 'projectless_source') {
                $source->update(['project_id' => null]);
            } elseif ($case === 'non_request_source') {
                $source->update(['document_type' => PaymentDocumentType::INVOICE]);
            }
            $payload = [
                'document_type' => PaymentDocumentType::PAYMENT_ORDER->value,
                'document_date' => now()->toDateString(), 'amount' => 900, 'currency' => 'RUB',
                'payer_organization_id' => $context->organization->id,
                'payee_contractor_id' => $contractor->id,
                'source_type' => PaymentDocument::class, 'source_id' => $source->id,
            ];
            if (in_array($case, ['matching', 'foreign_organization'], true)) {
                $payload['project_id'] = $project->id;
            } elseif ($case === 'explicit_null') {
                $payload['project_id'] = null;
            } elseif ($case === 'conflicting') {
                $payload['project_id'] = $otherProject->id;
            }
            $response = $this->withHeaders($context->authHeaders())
                ->postJson('/api/v1/admin/payments/documents', $payload);
            if (in_array($case, ['omitted', 'matching'], true)) {
                self::assertSame(201, $response->getStatusCode(), $case.': '.$response->getContent());
                $response->assertCreated()->assertJsonPath('data.project.id', $project->id);
            } else {
                $response->assertForbidden();
                self::assertSame(0, PaymentDocument::query()->where('source_type', PaymentDocument::class)
                    ->where('source_id', $source->id)->count());
            }
        }
        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public function test_accept_rejects_a_document_that_is_not_a_payment_request(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $document = $this->createPaymentRequest($context, $this->createContractor($context), PaymentDocumentStatus::APPROVED);
        $document->update(['document_type' => PaymentDocumentType::INVOICE]);

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/payments/requests/{$document->id}/accept")
            ->assertUnprocessable();

        self::assertSame(0, PaymentDocument::query()->where('source_id', $document->id)->count());
    }

    public function test_request_lists_separate_payer_and_payee_within_the_current_organization(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $contractor = $this->createContractor($context);
        $incoming = $this->createPaymentRequest($context, $contractor, PaymentDocumentStatus::APPROVED);
        $outgoing = $this->createPaymentRequest($context, $contractor, PaymentDocumentStatus::APPROVED);
        $outgoing->update([
            'payer_organization_id' => null,
            'payer_contractor_id' => $contractor->id,
            'payee_organization_id' => $context->organization->id,
            'payee_contractor_id' => null,
        ]);
        $selfContractor = Contractor::query()->create([
            'organization_id' => $context->organization->id,
            'source_organization_id' => $context->organization->id,
            'name' => 'Recipient organization contractor', 'inn' => '7700000011',
        ]);
        $viaContractor = $this->createPaymentRequest($context, $selfContractor, PaymentDocumentStatus::APPROVED);
        $viaContractor->update(['payer_organization_id' => null, 'payer_contractor_id' => $contractor->id]);
        $viaRecipient = $this->createPaymentRequest($context, $contractor, PaymentDocumentStatus::APPROVED);
        $viaRecipient->update([
            'payer_organization_id' => null, 'payer_contractor_id' => $contractor->id,
            'payee_contractor_id' => null, 'contractor_id' => null,
            'recipient_organization_id' => $context->organization->id,
        ]);
        $foreign = AdminApiTestContext::create(roleSlug: 'web_admin');
        $contractor->update(['source_organization_id' => $foreign->organization->id]);
        $foreignContractor = $this->createContractor($foreign);
        $foreignContractor->update(['source_organization_id' => $context->organization->id]);
        $foreignRequest = $this->createPaymentRequest($foreign, $foreignContractor, PaymentDocumentStatus::APPROVED);
        $foreignRequest->update([
            'payee_organization_id' => $context->organization->id,
            'recipient_organization_id' => $context->organization->id,
        ]);

        $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/payments/requests/incoming?status=approved')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $incoming->id);
        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/payments/requests/outgoing?status=approved')
            ->assertOk()->assertJsonCount(3, 'data');
        self::assertEqualsCanonicalizing([$outgoing->id, $viaContractor->id, $viaRecipient->id], array_column($response->json('data'), 'id'));
    }

    public function test_incoming_requests_return_existing_orders_and_hide_ambiguous_or_cross_project_links(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $contractor = $this->createContractor($context);
        $requests = [];
        foreach (['linked', 'multiple', 'cross-project', 'foreign-only', 'unlinked'] as $case) {
            $requests[$case] = $this->createPaymentRequest($context, $contractor, PaymentDocumentStatus::APPROVED);
        }
        $foreign = AdminApiTestContext::create(roleSlug: 'web_admin');
        $otherProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $linkedOrder = null;
        foreach (['linked', 'multiple', 'multiple', 'cross-project', 'foreign-only'] as $case) {
            $order = $requests[$case]->replicate();
            $order->fill([
                'organization_id' => $case === 'foreign-only' ? $foreign->organization->id : $context->organization->id,
                'project_id' => $case === 'cross-project' ? $otherProject->id : $requests[$case]->project_id,
                'document_type' => PaymentDocumentType::PAYMENT_ORDER,
                'document_number' => 'ORDER-'.uniqid(),
                'status' => PaymentDocumentStatus::DRAFT,
                'source_type' => PaymentDocument::class,
                'source_id' => $requests[$case]->id,
            ])->save();
            if ($case === 'linked') {
                $linkedOrder = $order;
            }
        }

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/payments/requests/incoming?status=approved')->assertOk();
        $rows = collect($response->json('data'))->keyBy('id');
        $this->assertSame([
            'id' => $linkedOrder->id,
            'document_number' => $linkedOrder->document_number,
            'status' => 'draft',
        ], $rows[$requests['linked']->id]['payment_order']);
        $this->assertFalse($rows[$requests['linked']->id]['payment_order_conflict']);
        foreach (['multiple', 'cross-project'] as $case) {
            $this->assertNull($rows[$requests[$case]->id]['payment_order']);
            $this->assertTrue($rows[$requests[$case]->id]['payment_order_conflict']);
        }
        foreach (['foreign-only', 'unlinked'] as $case) {
            $this->assertNull($rows[$requests[$case]->id]['payment_order']);
            $this->assertFalse($rows[$requests[$case]->id]['payment_order_conflict']);
        }
        $requests['unlinked']->forceFill(['created_at' => now()->subYear()])->save();
        $requests['foreign-only']->forceFill(['created_at' => now()->subYears(2)])->save();
        $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/payments/requests/incoming?status=approved&payment_order_state=unlinked&limit=1')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $requests['unlinked']->id);
        $linked = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/payments/requests/incoming?status=approved&payment_order_state=linked&limit=2')
            ->assertOk()->assertJsonCount(2, 'data');
        foreach ($linked->json('data') as $row) {
            $this->assertTrue($row['payment_order'] !== null || $row['payment_order_conflict']);
        }
        foreach (['payment_order_state=invalid', 'limit=0', 'limit=101'] as $invalidFilter) {
            $this->withHeaders($context->authHeaders())
                ->getJson('/api/v1/admin/payments/requests/incoming?'.$invalidFilter)->assertUnprocessable();
        }
    }

    public function test_payment_request_rejection_sets_rejected_status_and_statistics_count_it(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $contractor = $this->createContractor($context);
        $request = $this->createPaymentRequest($context, $contractor, PaymentDocumentStatus::SUBMITTED);

        $rejectResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/payments/requests/{$request->id}/reject", [
                'reason' => 'Нет закрывающих документов',
            ]);

        $rejectResponse->assertOk();
        $rejectResponse->assertJsonPath('data.status', PaymentDocumentStatus::REJECTED->value);

        $statisticsResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/payments/requests/statistics');

        $statisticsResponse->assertOk();
        $statisticsResponse->assertJsonPath('data.rejected_count', 1);
    }

    public function test_incoming_requests_accept_pending_status_alias(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $contractor = $this->createContractor($context);
        $pending = $this->createPaymentRequest($context, $contractor, PaymentDocumentStatus::PENDING_APPROVAL);
        $this->createPaymentRequest($context, $contractor, PaymentDocumentStatus::APPROVED);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/payments/requests/incoming?status=pending&per_page=20');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $pending->id);
        $response->assertJsonPath('data.0.status', PaymentDocumentStatus::PENDING_APPROVAL->value);
    }

    private function createContractor(AdminApiTestContext $context): Contractor
    {
        return Contractor::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Request Contractor',
            'inn' => '7700000010',
        ]);
    }

    public function test_contract_invoice_http_uses_saved_parties_and_currency_on_participant_project(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $customer = \App\Models\Organization::factory()->create();
        $project = \App\Models\Project::factory()->create(['organization_id' => $customer->id]);
        $project->organizations()->attach($context->organization->id, ['role' => 'contractor', 'role_new' => 'contractor', 'is_active' => true]);
        $executor = $this->createContractor($context);
        $executor->update(['source_organization_id' => $context->organization->id]);
        $contract = \App\Models\Contract::create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id, 'contractor_id' => $executor->id,
            'number' => 'HTTP-PAYMENT', 'date' => '2026-09-19', 'status' => 'active',
            'contract_side_type' => 'subcontract', 'base_amount' => 1000, 'total_amount' => 1000, 'currency' => 'USD',
        ]);
        foreach ([['first', 'contractor', $customer], ['second', 'subcontractor', $context->organization]] as [$side, $role, $organization]) {
            \App\Models\ContractParty::create([
                'contract_id' => $contract->id, 'side' => $side, 'role' => $role,
                'linked_organization_id' => $organization->id, 'name' => $organization->name, 'snapshot' => [],
            ]);
        }
        $payload = [
            'contract_id' => $contract->id, 'project_id' => $project->id,
            'document_type' => 'invoice', 'invoice_type' => 'advance', 'amount' => 100,
            'document_date' => '2026-09-19',
            'currency' => null, 'direction' => 'outgoing',
            'payer_organization_id' => $context->organization->id, 'payee_organization_id' => $customer->id,
        ];
        $response = $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/payments/documents', $payload);
        self::assertSame(201, $response->status(), $response->getContent());
        $payment = PaymentDocument::findOrFail($response->json('data.id'));
        self::assertSame('USD', $payment->currency);
        self::assertSame(InvoiceDirection::INCOMING, $payment->direction);
        self::assertSame($customer->id, $payment->payer_organization_id);
        self::assertSame($context->organization->id, $payment->payee_organization_id);
        self::assertSame($project->id, $payment->project_id);
        $project->organizations()->updateExistingPivot($context->organization->id, ['is_active' => false]);
        $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/payments/documents', $payload)
            ->assertNotFound();
        self::assertSame(1, PaymentDocument::query()->where('invoiceable_type', \App\Models\Contract::class)
            ->where('invoiceable_id', $contract->id)->count());
    }

    private function createPaymentRequest(
        AdminApiTestContext $context,
        Contractor $contractor,
        PaymentDocumentStatus $status
    ): PaymentDocument {
        return PaymentDocument::query()->create([
            'organization_id' => $context->organization->id,
            'payer_organization_id' => $context->organization->id,
            'payee_contractor_id' => $contractor->id,
            'contractor_id' => $contractor->id,
            'document_type' => PaymentDocumentType::PAYMENT_REQUEST,
            'document_number' => 'REQ-' . uniqid(),
            'document_date' => now()->toDateString(),
            'direction' => InvoiceDirection::OUTGOING,
            'amount' => 900,
            'paid_amount' => 0,
            'remaining_amount' => 900,
            'currency' => 'RUB',
            'status' => $status,
            'payment_purpose' => 'Оплата работ',
            'due_date' => now()->addDays(7)->toDateString(),
        ]);
    }

    private function assignPaymentPermissions(AdminApiTestContext $context, array $permissions, ?int $projectId = null, bool $replaceExisting = true): void
    {
        if ($replaceExisting) {
            UserRoleAssignment::query()->where('user_id', $context->user->id)->delete();
        }
        $role = OrganizationCustomRole::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Request order permission regression',
            'slug' => 'request-order-permissions-'.uniqid(),
            'system_permissions' => [],
            'module_permissions' => ['payments' => $permissions],
            'interface_access' => ['admin', 'mobile'],
            'conditions' => null, 'is_active' => true, 'created_by' => $context->user->id,
        ]);
        UserRoleAssignment::assignRole(
            user: $context->user, roleSlug: $role->slug,
            context: $projectId !== null ? AuthorizationContext::getProjectContext($projectId, (int) $context->organization->id)
                : AuthorizationContext::getOrganizationContext((int) $context->organization->id),
            roleType: UserRoleAssignment::TYPE_CUSTOM,
        );
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
                    'payments.invoice.view',
                    'payments.invoice.create',
                    'payments.invoice.edit',
                    'payments.invoice.issue',
                    'payments.transaction.approve',
                    'payments.transaction.reject',
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
    }
}
