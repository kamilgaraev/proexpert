<?php

declare(strict_types=1);

namespace App\Services\Billing\Bank;

use App\Models\CommercialBankInvoice;
use App\Models\CommercialBankOperation;
use App\Models\CommercialBankRefund;
use App\Models\OrganizationCommercialAccount;
use App\Models\OrganizationPackageSubscription;
use App\Models\OrganizationResourceAllocation;
use App\Models\SystemAdmin;
use App\Models\User;
use App\Modules\Core\AccessController;
use App\Services\Credits\AICreditCommercialService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class BankRefundService
{
    public function request(User $actor, int $organizationId, string $invoiceId, int $amount, string $kind, string $reason, string $key): array
    {
        app(BankBillingAccess::class)->organization($actor, $organizationId, true);

        return DB::transaction(function () use ($actor, $organizationId, $invoiceId, $amount, $kind, $reason, $key): array {
            $invoice = CommercialBankInvoice::query()->where('organization_id', $organizationId)
                ->where('public_id', $invoiceId)->lockForUpdate()->firstOrFail();
            $existing = CommercialBankRefund::query()->where('commercial_bank_invoice_id', $invoice->id)
                ->where('client_idempotency_key', $key)->get();
            if ($existing->isNotEmpty()) {
                if ((int) $existing->sum('amount_minor') !== $amount || $existing->contains(fn ($refund): bool => $refund->kind !== $kind || $refund->reason !== $reason)) {
                    throw new InvalidArgumentException(trans_message('bank_billing.refund_conflict'));
                }

                return ['items' => $existing->map(fn ($refund): array => ['id' => $refund->public_id, 'status' => $refund->status])->all()];
            }
            if ($amount <= 0 || ! in_array($kind, ['payment', 'overpayment'], true) || mb_strlen(trim($reason)) < 10) {
                throw new InvalidArgumentException(trans_message('bank_billing.refund_invalid'));
            }
            $operations = CommercialBankOperation::query()->where('matched_invoice_id', $invoice->id)
                ->where('direction', 'credit')->orderBy('id')->lockForUpdate()->get();
            $remaining = $amount;
            $items = [];
            foreach ($operations as $operation) {
                $reserved = (int) CommercialBankRefund::query()->where('commercial_bank_operation_id', $operation->id)
                    ->where('kind', $kind)->whereIn('status', ['requested', 'awaiting_bank', 'completed'])->sum('amount_minor');
                $capacity = $kind === 'overpayment' ? $operation->amount_minor - $operation->allocated_minor
                    : (int) $operation->allocations()->where('commercial_bank_invoice_id', $invoice->id)->sum('amount_minor');
                if ($kind === 'overpayment') {
                    $reserved -= (int) CommercialBankRefund::query()->where('commercial_bank_operation_id', $operation->id)
                        ->where('kind', $kind)->where('status', 'completed')->sum('amount_minor');
                }
                $part = min($remaining, max(0, $capacity - $reserved));
                if ($part === 0 || $operation->payer_account === null) {
                    continue;
                }
                $publicId = (string) Str::uuid();

                $refund = CommercialBankRefund::query()->create([
                    'public_id' => $publicId, 'commercial_bank_invoice_id' => $invoice->id,
                    'commercial_bank_operation_id' => $operation->id, 'amount_minor' => $part,
                    'kind' => $kind, 'reason' => $reason, 'client_idempotency_key' => $key,
                    'requested_by' => $actor->id, 'reference' => 'MOST-R-'.strtoupper(substr(str_replace('-', '', $publicId), 0, 16)),
                ]);
                $items[] = ['id' => $refund->public_id, 'status' => $refund->status];
                $remaining -= $part;
                if ($remaining === 0) {
                    return ['items' => $items];
                }
            }
            throw new InvalidArgumentException(trans_message('bank_billing.refund_invalid'));
        }, 3);
    }

    public function decide(SystemAdmin $actor, int $refundId, bool $approve): void
    {
        app(BankBillingAccess::class)->operator($actor);
        DB::transaction(function () use ($actor, $refundId, $approve): void {
            $refund = CommercialBankRefund::query()->whereKey($refundId)->lockForUpdate()->firstOrFail();
            if ($refund->status !== 'requested') {
                throw new InvalidArgumentException(trans_message('bank_billing.refund_conflict'));
            }
            $refund->forceFill(['status' => $approve ? 'awaiting_bank' : 'rejected',
                'approved_by' => $actor->id, 'approved_at' => now()])->save();
        }, 3);
    }

    public function completeFromBank(CommercialBankOperation $operation, ?string $transactionTypeCode = null): void
    {
        preg_match_all('/(?<![A-Z0-9])MOST-R-[0-9A-F]{16}(?![A-Z0-9])/i', $operation->purpose, $matches);
        $references = array_values(array_unique(array_map('strtoupper', $matches[0])));
        if (count($references) !== 1) {
            return;
        }
        $organizationId = DB::transaction(function () use ($operation, $references, $transactionTypeCode): ?int {
            $candidate = CommercialBankRefund::query()->where('reference', $references[0])->with('invoice.order')->first();
            if ($candidate === null) {
                return null;
            }
            $account = OrganizationCommercialAccount::query()->whereKey($candidate->invoice->order->commercial_account_id)
                ->where('organization_id', $candidate->invoice->organization_id)->lockForUpdate()->firstOrFail();
            $outgoing = CommercialBankOperation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail();
            $refund = CommercialBankRefund::query()->where('reference', $references[0])->lockForUpdate()->first();
            if ($refund === null || $refund->status !== 'awaiting_bank') {
                return null;
            }
            $source = CommercialBankOperation::query()->whereKey($refund->commercial_bank_operation_id)->lockForUpdate()->firstOrFail();
            if ($outgoing->direction !== 'debit' || $outgoing->amount_minor !== $refund->amount_minor
                || $outgoing->currency !== 'RUB' || $outgoing->recipient_account !== $source->payer_account
                || $outgoing->payer_account !== config('bank_billing.seller_account')) {
                $outgoing->forceFill(['review_reason' => 'refund_mismatch'])->save();

                return null;
            }
            if (mb_strtolower(str_replace('ё', 'е', trim($transactionTypeCode ?? ''))) !== 'платежное поручение') {
                $outgoing->forceFill(['review_reason' => 'refund_execution_pending'])->save();

                return null;
            }
            $invoice = CommercialBankInvoice::query()->whereKey($refund->commercial_bank_invoice_id)->lockForUpdate()->firstOrFail();
            $refund->forceFill(['status' => 'completed', 'completed_at' => now(), 'outgoing_operation_id' => $outgoing->id])->save();
            $outgoing->forceFill(['allocated_minor' => $outgoing->amount_minor, 'matched_invoice_id' => $invoice->id, 'review_reason' => null])->save();
            if ($refund->kind === 'overpayment') {
                $source->forceFill(['allocated_minor' => $source->allocated_minor + $refund->amount_minor])->save();

                return (int) $invoice->organization_id;
            }
            if ($invoice->paid_minor < $refund->amount_minor) {
                throw new InvalidArgumentException('bank_refund_exceeds_funded_amount');
            }
            $invoice->paid_minor -= $refund->amount_minor;
            $invoice->refunded_minor += $refund->amount_minor;
            $invoice->save();
            $order = $invoice->order;
            $payment = $invoice->payment;
            $refunded = min($payment->amount_minor, $payment->refunded_amount_minor + $refund->amount_minor);
            $payment->forceFill(['refunded_amount_minor' => $refunded])->save();
            if ($invoice->activated_at !== null) {
                app(AICreditCommercialService::class)->refund($order, $refunded);
            }
            if ($invoice->activated_at === null && $invoice->paid_minor > 0 && $invoice->paid_minor < $invoice->amount_minor) {
                $invoice->forceFill(['status' => 'partially_paid', 'paid_at' => null])->save();
                $order->forceFill(['status' => 'pending_payment'])->save();
                $payment->forceFill(['provider_status' => 'pending', 'terminal_at' => null])->save();
            }
            if ($invoice->paid_minor === 0 && $invoice->paid_at === null) {
                $invoice->forceFill(['status' => 'canceled'])->save();
                $order->forceFill(['status' => 'canceled'])->save();
                $invoice->payment->forceFill(['provider_status' => 'canceled', 'terminal_at' => now()])->save();
            }
            if ($invoice->paid_minor === 0 && $invoice->paid_at !== null) {
                $invoice->forceFill(['status' => 'refunded'])->save();
                $order->forceFill(['status' => 'refunded'])->save();
                $rows = OrganizationPackageSubscription::query()->where('organization_id', $invoice->organization_id)
                    ->where('source_order_id', $order->id)->lockForUpdate()->get();
                $restored = $order->renew_subscription && app(\App\Services\Billing\CommercialPaidPeriodService::class)
                    ->restoreAfterRefund($order, $account, $rows);
                if (! $restored) {
                    $rows->each(fn ($row) => $row->forceFill([
                        'status' => 'expired', 'current_period_end_at' => now(), 'canceled_at' => now(),
                    ])->save());
                }
                OrganizationResourceAllocation::query()->where('organization_id', $invoice->organization_id)
                    ->where('metadata->commercial_order_id', $order->public_id)->get()
                    ->each(fn ($row) => $row->forceFill(['status' => 'expired', 'period_end_at' => now()])->save());
                if (! $restored && ! OrganizationPackageSubscription::query()->where('organization_id', $invoice->organization_id)
                    ->whereIn('access_source', ['paid_package', 'full_suite'])->whereIn('status', ['active', 'grace', 'scheduled_for_removal'])
                    ->where('current_period_end_at', '>', now())->exists()) {
                    OrganizationCommercialAccount::query()->whereKey($order->commercial_account_id)
                        ->where('current_period_end_at', $order->period_end_at)
                        ->update(['status' => 'free', 'auto_renew_enabled' => false, 'saved_payment_method_active' => false]);
                }
            }
            $snapshot = $invoice->snapshot;
            $snapshot['amount_minor'] = $refund->amount_minor;
            app(BankDocumentService::class)->prepare($invoice, 'correction', $refund->public_id, $snapshot);

            return (int) $invoice->organization_id;
        }, 3);
        if ($organizationId !== null) {
            app(AccessController::class)->clearAccessCache($organizationId);
        }
    }
}
