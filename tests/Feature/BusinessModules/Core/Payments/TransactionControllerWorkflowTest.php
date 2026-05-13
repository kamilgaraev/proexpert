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
use App\Models\OrganizationModuleActivation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class TransactionControllerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_transaction_can_be_approved_without_immutable_model_failure(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $transaction = $this->createTransaction($this->createDocument($context), PaymentTransactionStatus::PENDING);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/payments/transactions/{$transaction->id}/approve", [
                'notes' => 'Проверено бухгалтерией',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', PaymentTransactionStatus::COMPLETED->value);
        $response->assertJsonPath('data.payment_method', PaymentMethod::BANK_TRANSFER->value);

        $this->assertDatabaseHas('payment_transactions', [
            'id' => $transaction->id,
            'status' => PaymentTransactionStatus::COMPLETED->value,
            'approved_by_user_id' => $context->user->id,
        ]);
    }

    public function test_pending_transaction_can_be_rejected_without_immutable_model_failure(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $transaction = $this->createTransaction($this->createDocument($context), PaymentTransactionStatus::PENDING);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/payments/transactions/{$transaction->id}/reject", [
                'reason' => 'Некорректные реквизиты',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', PaymentTransactionStatus::FAILED->value);

        $this->assertDatabaseHas('payment_transactions', [
            'id' => $transaction->id,
            'status' => PaymentTransactionStatus::FAILED->value,
        ]);
    }

    public function test_processing_transaction_can_be_cancelled_but_completed_transaction_requires_refund(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $document = $this->createDocument($context);
        $processing = $this->createTransaction($document, PaymentTransactionStatus::PROCESSING);
        $completed = $this->createTransaction($document, PaymentTransactionStatus::COMPLETED);

        $cancelResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/payments/transactions/{$processing->id}/cancel", [
                'reason' => 'Платеж не ушел в банк',
            ]);

        $cancelResponse->assertOk();
        $cancelResponse->assertJsonPath('data.status', PaymentTransactionStatus::CANCELLED->value);

        $completedCancelResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/payments/transactions/{$completed->id}/cancel", [
                'reason' => 'Нужно отменить',
            ]);

        $completedCancelResponse->assertStatus(422);

        $this->assertDatabaseHas('payment_transactions', [
            'id' => $completed->id,
            'status' => PaymentTransactionStatus::COMPLETED->value,
        ]);
    }

    public function test_completed_transaction_can_be_refunded_and_updates_document_amounts(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $document = $this->createDocument($context, [
            'amount' => 1000,
            'paid_amount' => 500,
            'remaining_amount' => 500,
            'status' => PaymentDocumentStatus::PARTIALLY_PAID,
        ]);
        $transaction = $this->createTransaction($document, PaymentTransactionStatus::COMPLETED, 300);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/payments/transactions/{$transaction->id}/refund", [
                'amount' => 200,
                'reason' => 'Частичный возврат',
                'refund_date' => now()->toDateString(),
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.original_transaction.status', PaymentTransactionStatus::REFUNDED->value);
        $response->assertJsonPath('data.refund_transaction.amount', -200);

        $document->refresh();
        $this->assertEquals(300.0, (float) $document->paid_amount);
        $this->assertEquals(700.0, (float) $document->remaining_amount);
    }

    public function test_transaction_index_filters_by_current_organization_document_and_preserves_meta(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $document = $this->createDocument($context);
        $matchingTransaction = $this->createTransaction($document, PaymentTransactionStatus::COMPLETED, 700);
        $this->createTransaction($this->createDocument($context), PaymentTransactionStatus::COMPLETED, 300);

        $foreignContext = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($foreignContext->organization->id);
        $this->createTransaction($this->createDocument($foreignContext), PaymentTransactionStatus::COMPLETED, 9900);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/payments/transactions?payment_document_id={$document->id}&status=&date_from=&date_to=&per_page=25&page=1");

        $response->assertOk();
        $response->assertJsonPath('data.0.id', $matchingTransaction->id);
        $response->assertJsonPath('data.0.payment_document_id', $document->id);
        $response->assertJsonPath('data.0.amount', 700);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonPath('meta.per_page', 25);
    }

    private function createDocument(AdminApiTestContext $context, array $overrides = []): PaymentDocument
    {
        return PaymentDocument::query()->create(array_merge([
            'organization_id' => $context->organization->id,
            'document_type' => PaymentDocumentType::INVOICE,
            'document_number' => 'PAY-' . uniqid(),
            'document_date' => now()->toDateString(),
            'direction' => InvoiceDirection::OUTGOING,
            'amount' => 1000,
            'paid_amount' => 0,
            'remaining_amount' => 1000,
            'status' => PaymentDocumentStatus::APPROVED,
            'due_date' => now()->addDays(7)->toDateString(),
        ], $overrides));
    }

    private function createTransaction(
        PaymentDocument $document,
        PaymentTransactionStatus $status,
        float $amount = 250
    ): PaymentTransaction {
        return PaymentTransaction::query()->create([
            'payment_document_id' => $document->id,
            'organization_id' => $document->organization_id,
            'project_id' => $document->project_id,
            'amount' => $amount,
            'currency' => 'RUB',
            'payment_method' => PaymentMethod::BANK_TRANSFER,
            'transaction_date' => now()->toDateString(),
            'status' => $status,
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
                'permissions' => [
                    'payments.transaction.view',
                    'payments.transaction.edit',
                    'payments.transaction.approve',
                    'payments.transaction.reject',
                    'payments.transaction.refund',
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
