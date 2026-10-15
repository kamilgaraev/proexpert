<?php

declare(strict_types=1);

namespace App\Services\Billing\Bank;

use App\Exceptions\Billing\PaymentGatewayConfigurationException;
use App\Models\CommercialBankInvoice;
use App\Models\CommercialOrder;
use App\Models\CommercialPayment;
use App\Models\Organization;
use App\Models\User;
use App\Services\Legal\LegalDocumentService;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class BankInvoiceService
{
    public function __construct(private readonly TochkaClient $bank, private readonly BankBillingAccess $access) {}

    public function assertReady(): void
    {
        $seller = (array) config('legal.provider', []);
        if (! $this->bank->ready() || trim((string) ($seller['name'] ?? '')) === ''
            || ! preg_match('/\A\d{12}\z/', (string) ($seller['inn'] ?? ''))
            || trim((string) ($seller['registration_number'] ?? '')) === ''
            || trim((string) ($seller['address'] ?? '')) === ''
            || trim((string) config('bank_billing.seller_bank')) === ''
            || ! preg_match('/\A\d{20}\z/', (string) config('bank_billing.seller_correspondent_account'))
            || ! preg_match('/\A\d{20}\z/', (string) config('bank_billing.seller_account'))
            || ! preg_match('/\A\d{9}\z/', (string) config('bank_billing.seller_bik'))
            || config('bank_billing.account_id') !== config('bank_billing.seller_account').'/'.config('bank_billing.seller_bik')) {
            throw new PaymentGatewayConfigurationException(trans_message('bank_billing.unavailable'));
        }
    }

    public function assertBuyer(Organization $organization): void
    {
        if (! preg_match('/\A(?:\d{10}|\d{12})\z/', (string) $organization->tax_number)
            || trim((string) ($organization->legal_name ?: $organization->name)) === ''
            || trim((string) $organization->address) === ''
            || trim((string) $organization->registration_number) === '') {
            throw new InvalidArgumentException(trans_message('bank_billing.buyer_required'));
        }
    }

    public function create(CommercialOrder $order, CommercialPayment $payment, ?User $actor = null): CommercialBankInvoice
    {
        $organization = $actor === null
            ? Organization::query()->findOrFail($order->organization_id)
            : $this->access->organization($actor, (int) $order->organization_id, true);
        if ($payment->provider !== 'tochka' || (int) $payment->commercial_order_id !== (int) $order->id
            || $order->currency !== 'RUB' || $payment->amount_minor !== $order->amount_minor) {
            throw new InvalidArgumentException('bank_invoice_order_mismatch');
        }

        return DB::transaction(function () use ($order, $payment, $organization): CommercialBankInvoice {
            CommercialOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $existing = CommercialBankInvoice::query()->where('commercial_order_id', $order->id)->first();
            if ($existing !== null) {
                return $existing;
            }
            $this->assertReady();
            $this->assertBuyer($organization);
            $number = 'MOST-'.str_pad((string) $order->id, 10, '0', STR_PAD_LEFT);
            $names = collect(app(PackageCatalogService::class)->allPackages())
                ->whereIn('slug', $order->selected_package_slugs ?? [])->pluck('name')->filter()->all();
            $description = trans_message($order->kind === 'ai_credits' ? 'bank_billing.ai_description' : 'bank_billing.subscription_description');
            if ($names !== []) {
                $description .= ': '.implode(', ', $names);
            }
            $snapshot = [
                'seller' => (array) config('legal.provider'),
                'bank' => [
                    'account' => (string) config('bank_billing.seller_account'),
                    'bik' => (string) config('bank_billing.seller_bik'),
                    'name' => (string) config('bank_billing.seller_bank'),
                    'correspondent_account' => (string) config('bank_billing.seller_correspondent_account'),
                ],
                'buyer' => ['name' => $organization->legal_name ?: $organization->name,
                    'inn' => $organization->tax_number, 'registration_number' => $organization->registration_number,
                    'address' => $organization->address, 'type' => strlen((string) $organization->tax_number) === 12 ? 'ip' : 'company'],
                'description' => $description,
                'amount_minor' => $order->amount_minor,
                'period_start_at' => $order->period_start_at?->toIso8601String(),
                'period_end_at' => $order->period_end_at?->toIso8601String(),
                'purpose' => trans_message('bank_billing.payment_purpose', ['number' => $number]),
                'legal_version' => config('legal.version'),
                'legal_sha256' => app(LegalDocumentService::class)->hash('offer'),
                'tax' => 'without_vat',
            ];
            $expires = now()->addDays((int) config('bank_billing.invoice_days', 7))->endOfDay();
            if ($order->kind === 'renewal') {
                $expires = $order->period_start_at->addDays((int) config('bank_billing.grace_days', 7));
            } elseif ($order->kind !== 'ai_credits' && $order->current_package_slugs !== []) {
                $expires = $expires->min($order->period_end_at);
            }
            $invoice = CommercialBankInvoice::query()->create([
                'public_id' => (string) Str::uuid(), 'organization_id' => $order->organization_id,
                'commercial_order_id' => $order->id, 'commercial_payment_id' => $payment->id,
                'number' => $number, 'amount_minor' => $order->amount_minor, 'snapshot' => $snapshot,
                'expires_at' => $expires,
            ]);
            app(BankDocumentService::class)->prepare($invoice, 'invoice');

            return $invoice;
        }, 3);
    }

    public function expireUnpaid(): void
    {
        CommercialBankInvoice::query()->where('status', 'awaiting_payment')->where('expires_at', '<', now())
            ->where('paid_minor', 0)->orderBy('id')->limit(100)->get()->each(function ($source): void {
                DB::transaction(function () use ($source): void {
                    $invoice = CommercialBankInvoice::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();
                    if ($invoice->status !== 'awaiting_payment' || $invoice->paid_minor !== 0 || $invoice->allocations()->exists()) {
                        return;
                    }
                    $invoice->forceFill(['status' => 'expired'])->save();
                    $invoice->order->forceFill(['status' => 'canceled'])->save();
                    $invoice->payment->forceFill(['provider_status' => 'canceled', 'terminal_at' => now()])->save();
                }, 3);
            });
    }

    public function assertNoFundedRenewal(\App\Models\OrganizationCommercialAccount $account): void
    {
        if (CommercialBankInvoice::query()->where('organization_id', $account->organization_id)
            ->whereHas('order', fn ($query) => $query->where('commercial_account_id', $account->id)->where('kind', 'renewal'))
            ->whereNull('activated_at')->where('paid_minor', '>', 0)->exists()) {
            throw new \App\Exceptions\Billing\CommercialCheckoutConflictException('A renewal invoice is already funded.');
        }
    }

    public function invalidateRenewalInvoices(\App\Models\OrganizationCommercialAccount $account, User $actor): void
    {
        $this->access->organization($actor, (int) $account->organization_id, true);
        $invoices = CommercialBankInvoice::query()->where('organization_id', $account->organization_id)
            ->whereHas('order', fn ($query) => $query->where('commercial_account_id', $account->id)->where('kind', 'renewal'))
            ->whereNull('activated_at')->whereIn('status', ['awaiting_payment', 'partially_paid', 'paid'])->lockForUpdate()->get();
        foreach ($invoices as $invoice) {
            if ($invoice->paid_minor !== 0 || $invoice->paid_at !== null || $invoice->allocations()->exists()) {
                throw new \App\Exceptions\Billing\CommercialBillingConflictException('A renewal invoice is already funded.');
            }
            $invoice->forceFill(['status' => 'canceled'])->save();
            $invoice->order->forceFill(['status' => 'canceled'])->save();
            $invoice->payment->forceFill(['provider_status' => 'canceled', 'terminal_at' => now()])->save();
        }
    }

    public function payload(CommercialBankInvoice $invoice): array
    {
        $invoice->loadMissing(['order', 'documents', 'refunds', 'operations']);
        $snapshot = $invoice->snapshot;

        return [
            'id' => $invoice->public_id, 'number' => $invoice->number, 'order_id' => $invoice->order->public_id,
            'status' => $invoice->status, 'amount_minor' => $invoice->amount_minor,
            'paid_minor' => $invoice->paid_minor, 'refunded_minor' => $invoice->refunded_minor,
            'remaining_minor' => $invoice->paid_at === null ? max(0, $invoice->amount_minor - $invoice->paid_minor) : 0,
            'currency' => $invoice->currency, 'purpose' => $snapshot['purpose'],
            'bank' => $snapshot['bank'], 'seller' => $snapshot['seller'], 'buyer' => $snapshot['buyer'],
            'description' => $snapshot['description'], 'expires_at' => $invoice->expires_at?->toJSON(),
            'paid_at' => $invoice->paid_at?->toJSON(), 'activated_at' => $invoice->activated_at?->toJSON(),
            'created_at' => $invoice->created_at?->toJSON(),
            'overpayment_minor' => (int) $invoice->operations->where('direction', 'credit')
                ->sum(fn ($operation): int => $operation->amount_minor - $operation->allocated_minor),
            'documents' => $invoice->documents->map(fn ($document): array => [
                'id' => $document->public_id, 'kind' => $document->kind, 'status' => $document->status,
                'issued_at' => $document->issued_at?->toJSON(), 'signature_status' => 'requires_signature',
            ])->all(),
            'refunds' => $invoice->refunds->map(fn ($refund): array => [
                'id' => $refund->public_id, 'status' => $refund->status, 'amount_minor' => $refund->amount_minor,
                'kind' => $refund->kind, 'created_at' => $refund->created_at?->toJSON(),
            ])->all(),
        ];
    }
}
