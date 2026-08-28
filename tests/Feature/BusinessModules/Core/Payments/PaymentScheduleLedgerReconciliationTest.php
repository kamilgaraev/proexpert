<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessModules\Core\Payments;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Models\PaymentSchedule;
use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use App\BusinessModules\Core\Payments\Services\PaymentDocumentService;
use App\BusinessModules\Core\Payments\Services\PaymentTransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class PaymentScheduleLedgerReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registered_payment_is_allocated_to_installments_in_due_date_order(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $document = $this->createScheduledDocument($context, '2100.00');
        [$first, $second] = $this->createSchedule($document);

        app(PaymentDocumentService::class)->registerPayment($document, '1050.00', [
            'payment_method' => 'bank_transfer',
            'reference_number' => 'PAY-SCHEDULE-001',
            'transaction_date' => '2026-09-02',
            'created_by_user_id' => $context->user->id,
        ]);

        $transaction = PaymentTransaction::query()
            ->where('payment_document_id', $document->id)
            ->sole();

        $first->refresh();
        $second->refresh();

        $this->assertSame('paid', $first->status);
        $this->assertSame('1050.00', $first->paid_amount);
        $this->assertSame($transaction->id, $first->payment_transaction_id);
        $this->assertSame('pending', $second->status);
        $this->assertSame('0.00', $second->paid_amount);
        $this->assertNull($second->payment_transaction_id);
    }

    public function test_refund_reopens_installment_and_restores_partial_allocation(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $document = $this->createScheduledDocument($context, '2100.00');
        [$first, $second] = $this->createSchedule($document);

        app(PaymentDocumentService::class)->registerPayment($document, '1050.00', [
            'payment_method' => 'bank_transfer',
            'reference_number' => 'PAY-SCHEDULE-REFUND-001',
            'transaction_date' => '2026-09-02',
            'created_by_user_id' => $context->user->id,
        ]);
        $transaction = PaymentTransaction::query()
            ->where('payment_document_id', $document->id)
            ->sole();

        app(PaymentTransactionService::class)->refundPayment(
            transactionId: $transaction->id,
            organizationId: $context->organization->id,
            actorId: $context->user->id,
            amount: '400.00',
            reason: 'Корректировка тестового платежа',
            refundDate: '2026-09-03',
            idempotencyKey: 'schedule-refund-20260828-0001',
        );

        $first->refresh();
        $second->refresh();

        $this->assertSame('pending', $first->status);
        $this->assertSame('650.00', $first->paid_amount);
        $this->assertNull($first->paid_at);
        $this->assertNull($first->payment_transaction_id);
        $this->assertSame('pending', $second->status);
        $this->assertSame('0.00', $second->paid_amount);
    }

    private function createScheduledDocument(AdminApiTestContext $context, string $amount): PaymentDocument
    {
        return PaymentDocument::query()->create([
            'organization_id' => $context->organization->id,
            'document_type' => PaymentDocumentType::PAYMENT_ORDER,
            'document_number' => 'SCHEDULE-'.uniqid(),
            'document_date' => '2026-08-28',
            'direction' => InvoiceDirection::OUTGOING,
            'amount' => $amount,
            'paid_amount' => '0.00',
            'remaining_amount' => $amount,
            'currency' => 'RUB',
            'status' => PaymentDocumentStatus::SCHEDULED,
            'due_date' => '2026-09-04',
            'scheduled_at' => '2026-09-02',
            'created_by_user_id' => $context->user->id,
        ]);
    }

    /**
     * @return array{PaymentSchedule, PaymentSchedule}
     */
    private function createSchedule(PaymentDocument $document): array
    {
        $first = PaymentSchedule::query()->create([
            'payment_document_id' => $document->id,
            'installment_number' => 1,
            'due_date' => '2026-09-02',
            'amount' => '1050.00',
            'paid_amount' => '0.00',
            'status' => 'pending',
        ]);
        $second = PaymentSchedule::query()->create([
            'payment_document_id' => $document->id,
            'installment_number' => 2,
            'due_date' => '2026-09-04',
            'amount' => '1050.00',
            'paid_amount' => '0.00',
            'status' => 'pending',
        ]);

        return [$first, $second];
    }
}
