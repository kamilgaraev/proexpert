<?php

declare(strict_types=1);

namespace App\Services\Billing\Bank;

use App\Models\CommercialBankAllocation;
use App\Models\CommercialBankInvoice;
use App\Models\CommercialBankOperation;
use App\Models\OrganizationCommercialAccount;
use App\Models\SystemAdmin;
use App\Services\Billing\CommercialWebhookService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class BankSettlementService
{
    public function ingest(array $transaction): ?CommercialBankOperation
    {
        if (($transaction['status'] ?? '') !== 'Booked') {
            return null;
        }
        $direction = strtolower((string) ($transaction['creditDebitIndicator'] ?? ''));
        $id = trim((string) ($transaction['transactionId'] ?? ''));
        $paymentId = trim((string) ($transaction['paymentId'] ?? '')) ?: null;
        $amount = BankMoney::minor($transaction['Amount']['amount'] ?? null);
        $date = (string) ($transaction['documentProcessDate'] ?? '');
        if ($id === '' || strlen($id) > 180 || strlen((string) $paymentId) > 180 || $amount < 1
            || ! in_array($direction, ['credit', 'debit'], true)
            || ! preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date)
            || ! CarbonImmutable::hasFormatWithModifiers($date, 'Y-m-d')
            || ($transaction['Amount']['currency'] ?? null) !== 'RUB') {
            throw new InvalidArgumentException('tochka_transaction_invalid');
        }
        $data = [
            'account_id' => (string) config('bank_billing.account_id'), 'transaction_id' => $id,
            'payment_id' => $paymentId, 'direction' => $direction, 'amount_minor' => $amount,
            'currency' => 'RUB', 'booked_on' => $date, 'purpose' => mb_substr((string) ($transaction['description'] ?? ''), 0, 8000),
            'payer_inn' => $this->digits($transaction['DebtorParty']['inn'] ?? null, 12),
            'payer_account' => $this->digits($transaction['DebtorAccount']['identification'] ?? null, 32),
            'recipient_inn' => $this->digits($transaction['CreditorParty']['inn'] ?? null, 12),
            'recipient_account' => $this->digits($transaction['CreditorAccount']['identification'] ?? null, 32),
        ];
        $ours = (string) config('bank_billing.seller_account');
        if (($direction === 'credit' ? $data['recipient_account'] : $data['payer_account']) !== $ours) {
            throw new InvalidArgumentException('tochka_transaction_account_mismatch');
        }
        $operation = DB::transaction(function () use ($data): CommercialBankOperation {
            CommercialBankOperation::query()->insertOrIgnore($data + ['created_at' => now(), 'updated_at' => now()]);
            $operation = CommercialBankOperation::query()->where('account_id', $data['account_id'])
                ->where(function ($query) use ($data): void {
                    $query->where('transaction_id', $data['transaction_id']);
                    if ($data['payment_id'] !== null) {
                        $query->orWhere(fn ($payment) => $payment->where('payment_id', $data['payment_id'])->where('direction', $data['direction']));
                    }
                })->lockForUpdate()->firstOrFail();
            foreach (['amount_minor', 'currency', 'direction', 'payer_inn', 'payer_account', 'recipient_inn', 'recipient_account', 'purpose'] as $field) {
                if ((string) $operation->getAttribute($field) !== (string) $data[$field]) {
                    $operation->forceFill(['review_reason' => 'bank_operation_changed'])->save();
                    break;
                }
            }

            return $operation;
        }, 3);
        if ($operation->review_reason === 'bank_operation_changed') {
            return $operation;
        }
        if ($direction === 'debit') {
            app(BankRefundService::class)->completeFromBank($operation);
        } else {
            $this->match($operation);
        }

        return $operation->refresh();
    }

    public function match(CommercialBankOperation $operation): void
    {
        if ($operation->allocated_minor >= $operation->amount_minor || $operation->reviewed_by !== null) {
            return;
        }
        preg_match_all('/(?<![A-Z0-9])MOST-\d{10}(?!\d)/i', $operation->purpose, $matches);
        $numbers = array_values(array_unique(array_map('strtoupper', $matches[0])));
        $invoice = count($numbers) === 1 ? CommercialBankInvoice::query()->where('number', $numbers[0])->first() : null;
        $reason = match (true) {
            $invoice === null => count($numbers) > 1 ? 'multiple_invoices' : 'invoice_not_found',
            ! in_array($invoice->status, ['awaiting_payment', 'partially_paid', 'paid'], true) => 'invoice_not_payable',
            $operation->payer_inn !== ($invoice->snapshot['buyer']['inn'] ?? null) => 'payer_mismatch',
            preg_match('/\A(?:40[567]\d{17}|40802\d{15})\z/', (string) $operation->payer_account) !== 1 => 'payer_not_b2b',
            $operation->booked_on->toDateString() > $invoice->expires_at->toDateString() => 'late_payment',
            $operation->booked_on->toDateString() < $invoice->created_at->setTimezone('Europe/Moscow')->toDateString() => 'payment_before_invoice',
            default => null,
        };
        if ($reason !== null) {
            $operation->forceFill(['review_reason' => $reason])->save();

            return;
        }
        $this->allocate((int) $operation->id, (int) $invoice->id);
    }

    public function allocateManually(SystemAdmin $actor, int $operationId, int $invoiceId, string $reason): void
    {
        app(BankBillingAccess::class)->operator($actor);
        if (mb_strlen(trim($reason)) < 10) {
            throw new InvalidArgumentException(trans_message('bank_billing.review_reason_required'));
        }
        $this->allocate($operationId, $invoiceId, (int) $actor->id, $reason);
    }

    public function exclude(SystemAdmin $actor, int $operationId, string $reason): void
    {
        app(BankBillingAccess::class)->operator($actor);
        if (mb_strlen(trim($reason)) < 10) {
            throw new InvalidArgumentException(trans_message('bank_billing.review_reason_required'));
        }
        DB::transaction(function () use ($actor, $operationId, $reason): void {
            $operation = CommercialBankOperation::query()->whereKey($operationId)->lockForUpdate()->firstOrFail();
            if ($operation->direction !== 'credit' || $operation->allocated_minor !== 0 || $operation->allocations()->exists()) {
                throw new InvalidArgumentException('bank_exclusion_invalid');
            }
            $operation->forceFill(['review_reason' => 'excluded', 'reviewed_by' => $actor->id, 'review_note' => $reason])->save();
        }, 3);
    }

    private function allocate(int $operationId, int $invoiceId, ?int $operatorId = null, ?string $reason = null): void
    {
        DB::transaction(function () use ($operationId, $invoiceId, $operatorId, $reason): void {
            $source = CommercialBankInvoice::query()->with('order')->findOrFail($invoiceId);
            OrganizationCommercialAccount::query()->whereKey($source->order->commercial_account_id)
                ->where('organization_id', $source->organization_id)->lockForUpdate()->firstOrFail();
            $operation = CommercialBankOperation::query()->whereKey($operationId)->lockForUpdate()->firstOrFail();
            $invoice = CommercialBankInvoice::query()->whereKey($invoiceId)->lockForUpdate()->firstOrFail();
            if ($operation->direction !== 'credit' || $operation->currency !== $invoice->currency
                || preg_match('/\A(?:40[567]\d{17}|40802\d{15})\z/', (string) $operation->payer_account) !== 1
                || preg_match('/\A(?:\d{10}|\d{12})\z/', (string) $operation->payer_inn) !== 1
                || $operation->review_reason === 'bank_operation_changed'
                || ! in_array($invoice->status, $operatorId === null ? ['awaiting_payment', 'partially_paid', 'paid'] : ['awaiting_payment', 'partially_paid', 'paid', 'expired'], true)
                || ($operation->matched_invoice_id !== null && (int) $operation->matched_invoice_id !== (int) $invoice->id)) {
                throw new InvalidArgumentException('bank_allocation_invalid');
            }
            $alreadyAllocated = CommercialBankAllocation::query()->where('commercial_bank_operation_id', $operation->id)
                ->where('commercial_bank_invoice_id', $invoice->id)->exists();
            $available = $operation->amount_minor - $operation->allocated_minor;
            $needed = $invoice->paid_at === null ? $invoice->amount_minor - $invoice->paid_minor : 0;
            $amount = min($available, $needed);
            if ($amount > 0 && ! $alreadyAllocated) {
                CommercialBankAllocation::query()->create(['commercial_bank_operation_id' => $operation->id,
                    'commercial_bank_invoice_id' => $invoice->id, 'amount_minor' => $amount,
                    'operator_id' => $operatorId, 'reason' => $reason]);
                $operation->allocated_minor += $amount;
                $invoice->paid_minor += $amount;
                $invoice->status = $invoice->paid_minor >= $invoice->amount_minor ? 'paid' : 'partially_paid';
                if ($invoice->status === 'paid') {
                    $invoice->paid_at = now();
                    $invoice->order->forceFill(['status' => 'paid'])->save();
                    $invoice->payment->forceFill(['provider_status' => 'succeeded', 'terminal_at' => now(),
                        'provider_payment_id' => 'bank-'.$invoice->public_id, 'reconciliation_required' => false, 'refunded_amount_minor' => 0])->save();
                }
                $invoice->save();
            }
            $operation->forceFill(['matched_invoice_id' => $invoice->id,
                'review_reason' => $operation->amount_minor > $operation->allocated_minor ? 'overpayment' : null,
                'reviewed_by' => $operatorId, 'review_note' => $reason])->save();
        }, 3);
        $this->activate($invoiceId);
    }

    public function activate(int $invoiceId): void
    {
        $invoice = CommercialBankInvoice::query()->with('order')->findOrFail($invoiceId);
        if ($invoice->status !== 'paid' || $invoice->activated_at !== null || $invoice->paid_minor < $invoice->amount_minor) {
            return;
        }
        if ($invoice->order->kind === 'renewal' && $invoice->order->period_start_at->isFuture()) {
            return;
        }
        if (! in_array($invoice->order->kind, ['renewal', 'ai_credits'], true)
            && $invoice->order->current_package_slugs !== [] && $invoice->order->period_end_at->isPast()) {
            $invoice->forceFill(['status' => 'review_required'])->save();
            $invoice->operations()->where('direction', 'credit')->update(['review_reason' => 'period_elapsed']);

            return;
        }
        app(CommercialWebhookService::class)->settleBankPayment($invoice);
    }

    private function digits(mixed $value, int $maximum): ?string
    {
        $text = trim((string) $value);

        return $text !== '' && strlen($text) <= $maximum && ctype_digit($text) ? $text : null;
    }
}
