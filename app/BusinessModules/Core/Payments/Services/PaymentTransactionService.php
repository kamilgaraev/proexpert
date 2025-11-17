<?php

namespace App\BusinessModules\Core\Payments\Services;

use App\BusinessModules\Core\Payments\Enums\InvoiceStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentTransactionStatus;
use App\BusinessModules\Core\Payments\Models\Invoice;
use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use Illuminate\Support\Facades\DB;

class PaymentTransactionService
{
    public function __construct(
        private readonly InvoiceService $invoiceService,
    ) {}

    /**
     * Зарегистрировать платёж
     */
    public function registerPayment(Invoice $invoice, array $data): PaymentTransaction
    {
        // Валидация суммы
        if ($data['amount'] <= 0) {
            throw new \InvalidArgumentException('Сумма платежа должна быть положительной');
        }

        if ($data['amount'] > $invoice->remaining_amount) {
            throw new \DomainException('Сумма платежа превышает остаток по счёту');
        }

        return DB::transaction(function () use ($invoice, $data) {
            // Создать транзакцию
            $transaction = PaymentTransaction::create(array_merge($data, [
                'invoice_id' => $invoice->id,
                'organization_id' => $invoice->organization_id,
                'project_id' => $invoice->project_id,
                'status' => PaymentTransactionStatus::COMPLETED,
                'created_by_user_id' => auth()->id(),
            ]));

            // Обновить счёт
            $this->updateInvoiceFromTransaction($invoice, $transaction);

            \Log::info('payments.transaction.registered', [
                'transaction_id' => $transaction->id,
                'invoice_id' => $invoice->id,
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

        $this->updateInvoiceFromTransaction($transaction->invoice, $transaction);

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
                'invoice_id' => $transaction->invoice_id,
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

            // Обновить счёт
            $invoice = $transaction->invoice;
            $invoice->paid_amount -= $amount;
            $invoice->remaining_amount += $amount;
            
            if ($invoice->status === InvoiceStatus::PAID) {
                $invoice->status = InvoiceStatus::PARTIALLY_PAID;
                $invoice->paid_at = null;
            }
            
            $invoice->save();

            \Log::info('payments.transaction.refunded', [
                'original_transaction_id' => $transaction->id,
                'refund_transaction_id' => $refund->id,
                'amount' => $amount,
            ]);

            return $refund;
        });
    }

    /**
     * Обновить счёт после транзакции
     */
    private function updateInvoiceFromTransaction(Invoice $invoice, PaymentTransaction $transaction): void
    {
        if ($transaction->amount < 0) {
            // Возврат - не обновляем здесь
            return;
        }

        $invoice->paid_amount += $transaction->amount;
        $invoice->remaining_amount = $invoice->calculateRemainingAmount();

        if ($invoice->remaining_amount <= 0) {
            $invoice->status = InvoiceStatus::PAID;
            $invoice->paid_at = now();
            $this->invoiceService->markAsPaid($invoice, $transaction);
        } elseif ($invoice->paid_amount > 0) {
            $invoice->status = InvoiceStatus::PARTIALLY_PAID;
        }

        $invoice->save();
    }
}

