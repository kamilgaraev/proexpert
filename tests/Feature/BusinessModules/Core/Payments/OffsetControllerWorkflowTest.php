<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessModules\Core\Payments;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Enums\PaymentMethod;
use App\BusinessModules\Core\Payments\Enums\PaymentTransactionStatus;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\Models\Contractor;
use App\Models\Module;
use App\Models\OrganizationModuleActivation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class OffsetControllerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_perform_offset_creates_paired_transactions_and_updates_document_balances(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $contractor = Contractor::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Offset Contractor',
            'inn' => '7700000001',
        ]);
        $receivable = $this->createOffsetDocument($context, $contractor, InvoiceDirection::INCOMING, [
            'document_number' => 'REC-001',
            'payer_contractor_id' => $contractor->id,
            'amount' => 500,
            'remaining_amount' => 500,
        ]);
        $payable = $this->createOffsetDocument($context, $contractor, InvoiceDirection::OUTGOING, [
            'document_number' => 'PAY-001',
            'payee_contractor_id' => $contractor->id,
            'amount' => 200,
            'remaining_amount' => 200,
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/payments/offsets/perform', [
                'receivable_id' => $receivable->id,
                'payable_id' => $payable->id,
                'amount' => 200,
                'notes' => 'Закрываем встречные обязательства',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.success', true);
        $response->assertJsonPath('data.receivable.remaining_amount', '300.00');
        $response->assertJsonPath('data.payable.remaining_amount', '0.00');

        $this->assertDatabaseHas('payment_transactions', [
            'payment_document_id' => $receivable->id,
            'amount' => '200.00',
            'payment_method' => PaymentMethod::OFFSET->value,
            'status' => PaymentTransactionStatus::COMPLETED->value,
        ]);
        $this->assertDatabaseHas('payment_transactions', [
            'payment_document_id' => $payable->id,
            'amount' => '200.00',
            'payment_method' => PaymentMethod::OFFSET->value,
            'status' => PaymentTransactionStatus::COMPLETED->value,
        ]);

        $receivable->refresh();
        $payable->refresh();
        $this->assertSame(PaymentDocumentStatus::PARTIALLY_PAID, $receivable->status);
        $this->assertSame(PaymentDocumentStatus::PAID, $payable->status);
        $this->assertEquals(300.0, (float) $receivable->remaining_amount);
        $this->assertEquals(0.0, (float) $payable->remaining_amount);
    }

    public function test_perform_offset_returns_validation_error_when_amount_exceeds_remaining_balance(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $contractor = Contractor::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Offset Contractor',
            'inn' => '7700000002',
        ]);
        $receivable = $this->createOffsetDocument($context, $contractor, InvoiceDirection::INCOMING, [
            'payer_contractor_id' => $contractor->id,
            'amount' => 100,
            'remaining_amount' => 100,
        ]);
        $payable = $this->createOffsetDocument($context, $contractor, InvoiceDirection::OUTGOING, [
            'payee_contractor_id' => $contractor->id,
            'amount' => 100,
            'remaining_amount' => 100,
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/payments/offsets/perform', [
                'receivable_id' => $receivable->id,
                'payable_id' => $payable->id,
                'amount' => 150,
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('payment_transactions', 0);
    }

    private function createOffsetDocument(
        AdminApiTestContext $context,
        Contractor $contractor,
        InvoiceDirection $direction,
        array $overrides = []
    ): PaymentDocument {
        return PaymentDocument::query()->create(array_merge([
            'organization_id' => $context->organization->id,
            'document_type' => PaymentDocumentType::INVOICE,
            'document_number' => 'OFF-' . uniqid(),
            'document_date' => now()->toDateString(),
            'direction' => $direction,
            'contractor_id' => $contractor->id,
            'amount' => 100,
            'paid_amount' => 0,
            'remaining_amount' => 100,
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
                    'payments.reconciliation.view',
                    'payments.reconciliation.perform',
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
