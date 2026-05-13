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
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModuleActivation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class ReconciliationControllerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconciliation_uses_current_organization_documents_and_optional_paid_documents(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $foreignContext = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $this->activatePaymentsModule($foreignContext->organization->id);

        $counterparty = Organization::factory()->verified()->create(['name' => 'ООО Подрядчик']);
        $incoming = $this->createDocument($context, $counterparty, [
            'document_number' => 'REC-IN-001',
            'direction' => InvoiceDirection::INCOMING,
            'amount' => 1000,
            'paid_amount' => 200,
            'remaining_amount' => 800,
            'status' => PaymentDocumentStatus::APPROVED,
        ]);
        $outgoing = $this->createDocument($context, $counterparty, [
            'document_number' => 'REC-OUT-001',
            'direction' => InvoiceDirection::OUTGOING,
            'amount' => 300,
            'paid_amount' => 0,
            'remaining_amount' => 300,
            'status' => PaymentDocumentStatus::SCHEDULED,
        ]);
        $this->createDocument($context, $counterparty, [
            'document_number' => 'REC-PAID-001',
            'direction' => InvoiceDirection::INCOMING,
            'amount' => 100,
            'paid_amount' => 100,
            'remaining_amount' => 0,
            'status' => PaymentDocumentStatus::PAID,
        ]);
        $this->createDocument($foreignContext, $counterparty, [
            'document_number' => 'REC-FOREIGN-001',
            'direction' => InvoiceDirection::INCOMING,
            'amount' => 9900,
            'paid_amount' => 0,
            'remaining_amount' => 9900,
            'status' => PaymentDocumentStatus::APPROVED,
        ]);
        $this->createDocument($context, $counterparty, [
            'document_number' => 'REC-OLD-001',
            'document_date' => '2026-04-30',
            'direction' => InvoiceDirection::INCOMING,
            'amount' => 700,
            'paid_amount' => 0,
            'remaining_amount' => 700,
            'status' => PaymentDocumentStatus::APPROVED,
        ]);

        $this->createTransaction($incoming, 200);
        $this->createTransaction($outgoing, 50);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/payments/reconciliation', [
                'counterparty_organization_id' => $counterparty->id,
                'period_from' => '2026-05-01',
                'period_to' => '2026-05-31',
                'include_paid' => false,
                'notes' => 'Сверка за май',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.counterparty', 'ООО Подрядчик');
        $response->assertJsonPath('data.period_from', '2026-05-01');
        $response->assertJsonPath('data.period_to', '2026-05-31');
        $response->assertJsonPath('data.our_balance', '-300');
        $response->assertJsonPath('data.their_balance', '800');
        $response->assertJsonPath('data.net_balance', '500');
        $response->assertJsonPath('data.documents_count', 2);
        $response->assertJsonPath('data.transactions_count', 2);
        $response->assertJsonPath('data.summary.include_paid', false);
        $response->assertJsonPath('data.summary.has_discrepancy', true);
        $response->assertJsonPath('data.summary.notes', 'Сверка за май');
        $response->assertJsonCount(2, 'data.documents_preview');
        $response->assertJsonPath('data.documents_preview.0.document_number', 'REC-IN-001');
        $response->assertJsonPath('data.documents_preview.1.document_number', 'REC-OUT-001');

        $withPaidResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/payments/reconciliation', [
                'counterparty_organization_id' => $counterparty->id,
                'period_from' => '2026-05-01',
                'period_to' => '2026-05-31',
                'include_paid' => true,
            ]);

        $withPaidResponse->assertOk();
        $withPaidResponse->assertJsonPath('data.documents_count', 3);
        $withPaidResponse->assertJsonPath('data.net_balance', '500');
        $withPaidResponse->assertJsonPath('data.summary.include_paid', true);
    }

    private function createDocument(
        AdminApiTestContext $context,
        Organization $counterparty,
        array $overrides = []
    ): PaymentDocument {
        return PaymentDocument::query()->create(array_merge([
            'organization_id' => $context->organization->id,
            'counterparty_organization_id' => $counterparty->id,
            'document_type' => PaymentDocumentType::INVOICE,
            'document_number' => 'REC-' . uniqid(),
            'document_date' => '2026-05-10',
            'direction' => InvoiceDirection::INCOMING,
            'amount' => 100,
            'paid_amount' => 0,
            'remaining_amount' => 100,
            'currency' => 'RUB',
            'status' => PaymentDocumentStatus::APPROVED,
            'due_date' => '2026-05-20',
        ], $overrides));
    }

    private function createTransaction(PaymentDocument $document, float $amount): PaymentTransaction
    {
        return PaymentTransaction::query()->create([
            'payment_document_id' => $document->id,
            'organization_id' => $document->organization_id,
            'project_id' => $document->project_id,
            'amount' => $amount,
            'currency' => 'RUB',
            'payment_method' => PaymentMethod::BANK_TRANSFER,
            'transaction_date' => '2026-05-11',
            'status' => PaymentTransactionStatus::COMPLETED,
        ]);
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
                'permissions' => ['payments.reconciliation.perform'],
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
