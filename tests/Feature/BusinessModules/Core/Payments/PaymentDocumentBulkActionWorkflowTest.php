<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessModules\Core\Payments;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\Models\Contract;
use App\Models\Module;
use App\Models\OrganizationModuleActivation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class PaymentDocumentBulkActionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_pay_paid_document_returns_item_failure_without_mutation(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $document = $this->createDocument($context, [
            'status' => PaymentDocumentStatus::PAID,
            'amount' => 1000,
            'paid_amount' => 1000,
            'remaining_amount' => 0,
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/payments/documents/bulk', [
                'ids' => [$document->id],
                'action' => 'pay',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.total_requested', 1);
        $response->assertJsonPath('data.success', 0);
        $response->assertJsonPath('data.failed', 1);
        $this->assertStringContainsString(
            'Документ нельзя оплатить в текущем статусе.',
            (string) $response->json('data.errors.0')
        );

        $document->refresh();
        $this->assertSame(PaymentDocumentStatus::PAID, $document->status);
        $this->assertEquals(1000.0, (float) $document->paid_amount);
        $this->assertEquals(0.0, (float) $document->remaining_amount);
        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public function test_bulk_pay_approved_document_registers_payment(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $document = $this->createDocument($context, [
            'status' => PaymentDocumentStatus::APPROVED,
            'amount' => 1000,
            'paid_amount' => 0,
            'remaining_amount' => 1000,
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/payments/documents/bulk', [
                'ids' => [$document->id],
                'action' => 'pay',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.total_requested', 1);
        $response->assertJsonPath('data.success', 1);
        $response->assertJsonPath('data.failed', 0);

        $document->refresh();
        $this->assertSame(PaymentDocumentStatus::PAID, $document->status);
        $this->assertEquals(1000.0, (float) $document->paid_amount);
        $this->assertEquals(0.0, (float) $document->remaining_amount);
        $this->assertDatabaseHas('payment_transactions', [
            'payment_document_id' => $document->id,
            'amount' => '1000.00',
            'status' => 'completed',
        ]);
    }

    public function test_register_payment_accepts_numeric_string_amount(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $document = $this->createDocument($context, [
            'status' => PaymentDocumentStatus::APPROVED,
            'amount' => 1000,
            'paid_amount' => 0,
            'remaining_amount' => 1000,
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->withHeader('Idempotency-Key', 'payment-numeric-string-1000')
            ->postJson("/api/v1/admin/payments/documents/{$document->id}/register-payment", [
                'amount' => '1000.00',
                'payment_method' => 'bank_transfer',
            ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $document->refresh();
        $this->assertSame(PaymentDocumentStatus::PAID, $document->status);
        $this->assertEquals(1000.0, (float) $document->paid_amount);
        $this->assertEquals(0.0, (float) $document->remaining_amount);
        $this->assertDatabaseHas('payment_transactions', [
            'payment_document_id' => $document->id,
            'amount' => '1000.00',
            'status' => 'completed',
        ]);
    }

    public function test_bulk_payment_locks_all_contracts_in_id_order_before_documents(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $contracts = [];
        foreach (['LOW', 'HIGH'] as $number) {
            $contracts[] = Contract::query()->create([
                'organization_id' => $context->organization->id, 'number' => 'BULK-'.$number,
                'date' => now()->toDateString(), 'subject' => 'Lock order', 'total_amount' => 5000, 'status' => 'active',
            ]);
        }
        $documents = [];
        foreach (array_reverse($contracts) as $contract) {
            $documents[] = $this->createDocument($context, [
                'source_type' => Contract::class, 'source_id' => $contract->id,
                'status' => PaymentDocumentStatus::PAID, 'paid_amount' => 1000, 'remaining_amount' => 0,
            ]);
        }
        $locks = [];
        DB::listen(static function (QueryExecuted $query) use (&$locks): void {
            if (str_contains(strtolower($query->sql), 'for update')
                && (str_contains($query->sql, '"contracts"') || str_contains($query->sql, '"payment_documents"'))) {
                $locks[] = $query->sql;
            }
        });

        $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/payments/documents/bulk', [
            'ids' => array_map(static fn (PaymentDocument $document): int => $document->id, $documents), 'action' => 'pay',
        ])->assertOk()->assertJsonPath('data.success', 0)->assertJsonPath('data.failed', 2);

        self::assertGreaterThanOrEqual(2, count($locks));
        self::assertStringContainsString('"contracts"', $locks[0]);
        self::assertMatchesRegularExpression('/order by.*"id" asc/i', $locks[0]);
        self::assertStringContainsString('"payment_documents"', $locks[1]);
        self::assertSame(0, \App\BusinessModules\Core\Payments\Models\PaymentTransaction::query()->count());
        foreach ($documents as $document) {
            self::assertSame(PaymentDocumentStatus::PAID, $document->fresh()->status);
        }
    }

    public function test_payment_registration_aborts_if_contract_reference_changes_before_document_lock(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $contracts = [];
        foreach (['INITIAL', 'CHANGED'] as $number) {
            $contracts[] = Contract::query()->create([
                'organization_id' => $context->organization->id, 'number' => 'RACE-'.$number,
                'date' => now()->toDateString(), 'subject' => 'Reference race', 'total_amount' => 5000, 'status' => 'active',
            ]);
        }
        $activeDocumentId = null;
        $changed = false;
        DB::listen(static function (QueryExecuted $query) use (&$activeDocumentId, &$changed, $contracts): void {
            if ($activeDocumentId === null || $changed || ! str_contains(strtolower($query->sql), 'for update')
                || ! str_contains($query->sql, '"contracts"')) {
                return;
            }
            $changed = true;
            PaymentDocument::query()->whereKey($activeDocumentId)->update(['source_id' => $contracts[1]->id]);
        });
        foreach (['single', 'bulk'] as $operation) {
            $document = $this->createDocument($context, [
                'source_type' => Contract::class, 'source_id' => $contracts[0]->id,
            ]);
            $before = $document->fresh()->getAttributes();
            $activeDocumentId = $document->id;
            $changed = false;
            $url = $operation === 'bulk'
                ? '/api/v1/admin/payments/documents/bulk'
                : '/api/v1/admin/payments/documents/'.$document->id.'/register-payment';
            $payload = $operation === 'bulk'
                ? ['ids' => [$document->id], 'action' => 'pay']
                : ['amount' => 1000, 'payment_method' => 'bank_transfer'];
            $this->withHeaders($context->authHeaders())
                ->withHeader('Idempotency-Key', 'contract-reference-race-'.$operation.'-'.$document->id)
                ->postJson($url, $payload)->assertNotFound();
            self::assertTrue($changed);
            self::assertSame($before, $document->fresh()->getAttributes());
            self::assertSame(0, $document->transactions()->count());
            $activeDocumentId = null;
        }
    }

    public function test_register_payment_preserves_cents_for_large_decimal_amount(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $document = $this->createDocument($context, [
            'status' => PaymentDocumentStatus::APPROVED,
            'amount' => '9999999999999.99',
            'paid_amount' => '0.00',
            'remaining_amount' => '9999999999999.99',
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->withHeader('Idempotency-Key', 'payment-large-decimal-exact')
            ->postJson("/api/v1/admin/payments/documents/{$document->id}/register-payment", [
                'amount' => '9999999999999.99',
                'payment_method' => 'bank_transfer',
            ]);

        $response->assertOk();
        $document->refresh();
        $this->assertSame('9999999999999.99', (string) $document->paid_amount);
        $this->assertSame('0.00', (string) $document->remaining_amount);
        $this->assertDatabaseHas('payment_transactions', [
            'payment_document_id' => $document->id,
            'amount' => '9999999999999.99',
        ]);
    }

    private function createDocument(AdminApiTestContext $context, array $overrides = []): PaymentDocument
    {
        return PaymentDocument::query()->create(array_merge([
            'organization_id' => $context->organization->id,
            'document_type' => PaymentDocumentType::PAYMENT_ORDER,
            'document_number' => 'BULK-'.uniqid(),
            'document_date' => now()->toDateString(),
            'direction' => InvoiceDirection::OUTGOING,
            'amount' => 1000,
            'paid_amount' => 0,
            'remaining_amount' => 1000,
            'currency' => 'RUB',
            'status' => PaymentDocumentStatus::APPROVED,
            'due_date' => now()->addDays(7)->toDateString(),
        ], $overrides));
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
    }
}
