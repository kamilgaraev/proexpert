<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Services;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Models\PaymentSchedule;
use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class PaymentScheduleLedgerReconciliationService
{
    public function reconcile(
        PaymentDocument $document,
        ?PaymentTransaction $transaction = null,
    ): void {
        $unallocatedPaidAmount = BigDecimal::of((string) $document->paid_amount)
            ->toScale(2, RoundingMode::HalfUp);
        $transactionCanCloseInstallment = $transaction instanceof PaymentTransaction
            && BigDecimal::of((string) $transaction->amount)->isPositive();

        $installments = PaymentSchedule::query()
            ->where('payment_document_id', $document->id)
            ->lockForUpdate()
            ->orderBy('due_date')
            ->orderBy('installment_number')
            ->orderBy('id')
            ->get();

        foreach ($installments as $installment) {
            $installmentAmount = BigDecimal::of((string) $installment->amount)
                ->toScale(2, RoundingMode::HalfUp);
            $allocatedAmount = $unallocatedPaidAmount->isPositive()
                ? ($unallocatedPaidAmount->isGreaterThan($installmentAmount)
                    ? $installmentAmount
                    : $unallocatedPaidAmount)
                : BigDecimal::zero()->toScale(2);
            $isPaid = $installmentAmount->isPositive()
                && $allocatedAmount->isEqualTo($installmentAmount);
            $wasPaid = $installment->status === 'paid';

            $installment->forceFill([
                'paid_amount' => (string) $allocatedAmount,
                'status' => $isPaid ? 'paid' : 'pending',
                'paid_at' => $isPaid
                    ? ($wasPaid ? $installment->paid_at : ($transaction?->transaction_date ?? now()))
                    : null,
                'payment_transaction_id' => $isPaid
                    ? ($wasPaid
                        ? $installment->payment_transaction_id
                        : ($transactionCanCloseInstallment ? $transaction?->id : null))
                    : null,
            ])->save();

            $unallocatedPaidAmount = $unallocatedPaidAmount->minus($allocatedAmount);
        }
    }
}
