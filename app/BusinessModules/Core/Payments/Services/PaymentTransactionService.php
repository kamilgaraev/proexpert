<?php

namespace App\BusinessModules\Core\Payments\Services;

use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentTransactionStatus;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use App\BusinessModules\Core\Payments\Services\PaymentDocumentService;
use Illuminate\Support\Facades\DB;

class PaymentTransactionService
{
    public function __construct(
        private readonly PaymentDocumentService $paymentDocumentService,
    ) {}

    /**
     * Зарегистрировать платёж
     */
    public function registerPayment(PaymentDocument $document, array $data): PaymentTransaction
    {
        // Валидация суммы
        if ($data['amount'] <= 0) {
            throw new \InvalidArgumentException('Сумма платежа должна быть положительной');
        }

        if ($data['amount'] > $document->remaining_amount) {
            throw new \DomainException('Сумма платежа превышает остаток по документу');
        }

        return DB::transaction(function () use ($document, $data) {
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
                throw new \DomainException('Платёж с таким номером уже существует');
            }
        }

        return true;
    }

    /**
     * Возврат платежа
     */
    public function refundPayment(PaymentTransaction $transaction, float $amount, string $reason): PaymentTransaction
    {
        if (!$transaction->canBeRefunded()) {
            throw new \DomainException('Транзакция не может быть возвращена');
        }

        if ($amount > $transaction->amount) {
            throw new \DomainException('Сумма возврата превышает сумму платежа');
        }

        return DB::transaction(function () use ($transaction, $amount, $reason) {
            // Создать обратную транзакцию
            $refund = PaymentTransaction::create([
                'payment_document_id' => $transaction->payment_document_id,
                'organization_id' => $transaction->organization_id,
                'project_id' => $transaction->project_id,
                'amount' => -$amount, // Отрицательная сумма
                'currency' => $transaction->currency,
                'payment_method' => $transaction->payment_method,
                'transaction_date' => now(),
                'status' => PaymentTransactionStatus::REFUNDED,
                'notes' => "Возврат платежа #{$transaction->id}. Причина: {$reason}",
                'created_by_user_id' => auth()->id(),
            ]);

            // Обновить статус оригинальной транзакции
            $transaction->update([
                'status' => PaymentTransactionStatus::REFUNDED,
            ]);

            // Обновить документ
            $document = $transaction->paymentDocument;
            $document->paid_amount -= $amount;
            $document->remaining_amount += $amount;
            
            if ($document->status === PaymentDocumentStatus::PAID) {
                $document->status = PaymentDocumentStatus::PARTIALLY_PAID;
                $document->paid_at = null;
            }
            
            $document->save();

            \Log::info('payments.transaction.refunded', [
                'original_transaction_id' => $transaction->id,
                'refund_transaction_id' => $refund->id,
                'amount' => $amount,
            ]);

            return $refund;
        });
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
}

