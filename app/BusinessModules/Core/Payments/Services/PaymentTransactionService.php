<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Services;

use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentTransactionStatus;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use function trans_message;

class PaymentTransactionService
{
    public function __construct(
        private readonly PaymentDocumentService $paymentDocumentService,
        private readonly PaymentBudgetLimitService $budgetLimitService,
        private readonly PurchaseOrderContractRequirementService $contractRequirement,
        private readonly PaymentScheduleLedgerReconciliationService $scheduleReconciliation,
    ) {}

    /**
     * Зарегистрировать платёж
     */
    public function registerPayment(PaymentDocument $document, array $data): PaymentTransaction
    {
        // Валидация суммы
        if ($data['amount'] <= 0) {
            throw new \InvalidArgumentException(trans_message('payments.validation.payment_amount_positive'));
        }

        if ($data['amount'] > $document->remaining_amount) {
            throw new \DomainException(trans_message('payments.validation.payment_amount_exceeds_document_remaining'));
        }

        return DB::transaction(function () use ($document, $data) {
            $document = PaymentDocument::query()
                ->whereKey($document->id)
                ->lockForUpdate()
                ->firstOrFail();
            if (! $document->canBePaid()) {
                throw new \DomainException(trans_message('payments.validation.document_pay_forbidden'));
            }
            if ($data['amount'] > $document->remaining_amount) {
                throw new \DomainException(trans_message('payments.validation.payment_amount_exceeds_document_remaining'));
            }
            $this->contractRequirement->assertPaymentAllowed($document);
            $this->budgetLimitService->assertAllowed(
                $document,
                PaymentBudgetLimitService::OPERATION_PAYMENT_REGISTER,
                (string) $data['amount'],
                auth()->user(),
                $data['budget_override_reason'] ?? null,
                $this->paymentOperationDate($data),
                true
            );

            // Создать транзакцию
            $transaction = PaymentTransaction::create(array_merge($data, [
                'payment_document_id' => $document->id,
                'organization_id' => $document->organization_id,
                'project_id' => $document->project_id,
                'status' => PaymentTransactionStatus::COMPLETED,
                'created_by_user_id' => auth()->id(),
            ]));

            // Обновить документ
            $this->updateDocumentFromTransaction($document, $transaction);
            $freshDocument = $document->fresh();
            $this->scheduleReconciliation->reconcile($freshDocument, $transaction);
            $this->budgetLimitService->convertAfterPayment($freshDocument, $transaction);

            \Log::info('payments.transaction.registered', [
                'transaction_id' => $transaction->id,
                'payment_document_id' => $document->id,
                'amount' => $transaction->amount,
            ]);

            return $transaction;
        });
    }

    /**
     * Обработать платёж
     */
    public function processPayment(PaymentTransaction $transaction): bool
    {
        if ($transaction->status !== PaymentTransactionStatus::PENDING) {
            return false;
        }

        $transaction->update([
            'status' => PaymentTransactionStatus::PROCESSING,
        ]);

        // Здесь может быть логика обработки через gateway

        $transaction->update([
            'status' => PaymentTransactionStatus::COMPLETED,
        ]);

        $this->updateDocumentFromTransaction($transaction->paymentDocument, $transaction);
        $this->budgetLimitService->convertAfterPayment($transaction->paymentDocument->fresh(), $transaction);

        return true;
    }

    /**
     * Верифицировать платёж
     */
    public function verifyPayment(PaymentTransaction $transaction): bool
    {
        // Проверка reference_number на дубли
        if ($transaction->reference_number) {
            $duplicate = PaymentTransaction::where('reference_number', $transaction->reference_number)
                ->where('id', '!=', $transaction->id)
                ->where('status', PaymentTransactionStatus::COMPLETED)
                ->exists();

            if ($duplicate) {
                throw new \DomainException(trans_message('payments.validation.transaction_duplicate_reference'));
            }
        }

        return true;
    }

    /**
     * Возврат платежа
     */
    public function refundPayment(
        int $transactionId,
        int $organizationId,
        int $actorId,
        string|float|int|null $amount,
        string $reason,
        string|\DateTimeInterface|null $refundDate,
        string $idempotencyKey
    ): array {
        return DB::transaction(function () use (
            $transactionId,
            $organizationId,
            $actorId,
            $amount,
            $reason,
            $refundDate,
            $idempotencyKey
        ): array {
            $transaction = PaymentTransaction::query()
                ->where('organization_id', $organizationId)
                ->whereKey($transactionId)
                ->lockForUpdate()
                ->firstOrFail();

            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [
                    "refund:{$organizationId}:{$idempotencyKey}",
                ]);
            }

            $existingRefund = PaymentTransaction::query()
                ->where('organization_id', $organizationId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existingRefund instanceof PaymentTransaction) {
                $requestedAmount = $amount === null
                    ? BigDecimal::of((string) $existingRefund->amount)->abs()
                    : BigDecimal::of((string) $amount)->toScale(2, RoundingMode::HalfUp);
                if ((int) $existingRefund->reverses_transaction_id !== $transactionId
                    || ! BigDecimal::of((string) $existingRefund->amount)->abs()->isEqualTo($requestedAmount)
                    || (string) data_get($existingRefund->metadata, 'refund_reason') !== $reason
                    || ($refundDate !== null
                        && $existingRefund->transaction_date?->toDateString()
                            !== \Illuminate\Support\Carbon::parse($refundDate)->toDateString())) {
                    throw new \DomainException(trans_message('payments.validation.idempotency_conflict'));
                }

                return [
                    'original_transaction' => $transaction->fresh(),
                    'refund_transaction' => $existingRefund,
                ];
            }

            if ($transaction->status !== PaymentTransactionStatus::COMPLETED) {
                throw new \DomainException(trans_message('payments.transactions.completed_only'));
            }

            $alreadyRefunded = BigDecimal::of((string) PaymentTransaction::query()
                ->where('reverses_transaction_id', $transaction->id)
                ->where('status', PaymentTransactionStatus::COMPLETED->value)
                ->sum('amount'))->abs();
            $availableToRefund = BigDecimal::of((string) $transaction->amount)
                ->minus($alreadyRefunded)
                ->toScale(2, RoundingMode::HalfUp);
            $refundAmount = $amount === null
                ? $availableToRefund
                : BigDecimal::of((string) $amount)->toScale(2, RoundingMode::HalfUp);
            if (! $refundAmount->isPositive() || $refundAmount->isGreaterThan($availableToRefund)) {
                throw new \DomainException(trans_message('payments.transactions.refund_amount_invalid'));
            }

            $document = PaymentDocument::query()
                ->where('organization_id', $organizationId)
                ->whereKey($transaction->payment_document_id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($refundAmount->isGreaterThan(BigDecimal::of((string) $document->paid_amount))) {
                throw new \DomainException(trans_message('payments.transactions.refund_amount_invalid'));
            }

            $refund = PaymentTransaction::query()->create([
                'payment_document_id' => $transaction->payment_document_id,
                'organization_id' => $organizationId,
                'project_id' => $transaction->project_id,
                'amount' => (string) $refundAmount->negated(),
                'currency' => $transaction->currency,
                'payment_method' => $transaction->payment_method,
                'transaction_date' => $refundDate ?? now()->toDateString(),
                'status' => PaymentTransactionStatus::COMPLETED->value,
                'notes' => trans_message('payments.transactions.refund_note', ['reason' => $reason]),
                'created_by_user_id' => $actorId,
                'approved_by_user_id' => $actorId,
                'reverses_transaction_id' => $transaction->id,
                'idempotency_key' => $idempotencyKey,
                'metadata' => [
                    'original_transaction_id' => $transaction->id,
                    'refund_reason' => $reason,
                ],
            ]);

            $paidAmount = BigDecimal::of((string) $document->paid_amount)
                ->minus($refundAmount)
                ->toScale(2, RoundingMode::HalfUp);
            $remainingAmount = BigDecimal::of((string) $document->amount)
                ->minus($paidAmount)
                ->toScale(2, RoundingMode::HalfUp);
            $document->forceFill([
                'paid_amount' => (string) $paidAmount,
                'remaining_amount' => (string) $remainingAmount,
                'status' => $paidAmount->isPositive()
                    ? PaymentDocumentStatus::PARTIALLY_PAID
                    : PaymentDocumentStatus::APPROVED,
                'paid_at' => null,
            ])->save();

            $freshDocument = $document->fresh();
            $this->scheduleReconciliation->reconcile($freshDocument, $refund);
            $this->budgetLimitService->reconcileAfterLedgerChange($freshDocument, $refund);
            $this->paymentDocumentService->synchronizeFinancialProjections($document);

            DB::afterCommit(static function () use ($transaction, $refund, $refundAmount): void {
                \Log::info('payments.transaction.refunded', [
                    'original_transaction_id' => $transaction->id,
                    'refund_transaction_id' => $refund->id,
                    'amount' => (string) $refundAmount,
                ]);
            });

            return [
                'original_transaction' => $transaction->fresh(),
                'refund_transaction' => $refund,
            ];
        }, 3);
    }

    /**
     * Обновить документ после транзакции
     */
    private function updateDocumentFromTransaction(PaymentDocument $document, PaymentTransaction $transaction): void
    {
        if ($transaction->amount < 0) {
            // Возврат - не обновляем здесь
            return;
        }

        $document->paid_amount += $transaction->amount;
        $document->remaining_amount = $document->calculateRemainingAmount();

        if ($document->remaining_amount <= 0) {
            $document->status = PaymentDocumentStatus::PAID;
            $document->paid_at = now();
            $this->paymentDocumentService->markAsPaid($document, $transaction);
        } elseif ($document->paid_amount > 0 && $document->status !== PaymentDocumentStatus::PARTIALLY_PAID) {
            $document->status = PaymentDocumentStatus::PARTIALLY_PAID;
        }

        $document->save();
    }

    private function paymentOperationDate(array $data): Carbon
    {
        $date = $data['transaction_date'] ?? $data['payment_date'] ?? now();

        if ($date instanceof \DateTimeInterface) {
            return Carbon::instance($date);
        }

        return Carbon::parse($date);
    }
}
