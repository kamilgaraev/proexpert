<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessModules\Core\Payments;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\Models\Module;
use App\Models\OrganizationModuleActivation;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    private function createDocument(AdminApiTestContext $context, array $overrides = []): PaymentDocument
    {
        return PaymentDocument::query()->create(array_merge([
            'organization_id' => $context->organization->id,
            'document_type' => PaymentDocumentType::PAYMENT_ORDER,
            'document_number' => 'BULK-' . uniqid(),
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
