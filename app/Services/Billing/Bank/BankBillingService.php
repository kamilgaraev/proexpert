<?php

declare(strict_types=1);

namespace App\Services\Billing\Bank;

use App\Models\CommercialBankDocument;
use App\Models\CommercialBankInvoice;
use App\Models\OrganizationCommercialAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class BankBillingService
{
    public function __construct(private readonly BankBillingAccess $access, private readonly BankInvoiceService $invoices) {}

    public function capabilities(User $actor, int $organizationId): array
    {
        $organization = $this->access->organization($actor, $organizationId);
        $ready = $buyerReady = true;
        try {
            $this->invoices->assertReady();
        } catch (\App\Exceptions\Billing\PaymentGatewayConfigurationException) {
            $ready = false;
        }
        try {
            $this->invoices->assertBuyer($organization);
        } catch (InvalidArgumentException) {
            $buyerReady = false;
        }

        return ['provider' => config('bank_billing.default_provider', 'tochka'), 'ready' => $ready,
            'sbp_b2b_enabled' => $ready && app(BankQrService::class)->enabled(),
            'buyer_ready' => $buyerReady, 'card_payments_enabled' => config('bank_billing.default_provider') === 'yookassa' && config('services.yookassa.payments_enabled') === true,
            'auto_invoice_enabled' => (bool) OrganizationCommercialAccount::query()->where('organization_id', $organizationId)->value('bank_auto_invoice')];
    }

    public function invoices(User $actor, int $organizationId, int $page, int $perPage): array
    {
        $this->access->organization($actor, $organizationId);
        $paginator = CommercialBankInvoice::query()->where('organization_id', $organizationId)
            ->with(['order', 'documents', 'refunds', 'operations', 'paymentProof'])->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page);

        return ['items' => $paginator->getCollection()->map(fn ($invoice) => $this->invoices->payload($invoice))->all(),
            'meta' => ['current_page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(), 'total' => $paginator->total()]];
    }

    public function cancel(User $actor, int $organizationId, string $invoiceId): void
    {
        $this->access->organization($actor, $organizationId, true);
        DB::transaction(function () use ($organizationId, $invoiceId): void {
            $invoice = CommercialBankInvoice::query()->where('organization_id', $organizationId)
                ->where('public_id', $invoiceId)->lockForUpdate()->firstOrFail();
            if ($invoice->paid_minor > 0 || $invoice->paid_at !== null || $invoice->allocations()->exists()) {
                throw new InvalidArgumentException(trans_message('bank_billing.cancel_funded'));
            }
            $invoice->forceFill(['status' => 'canceled'])->save();
            app(BankPaymentProofService::class)->cancel($invoice);
            $invoice->order->forceFill(['status' => 'canceled'])->save();
            $invoice->payment->forceFill(['provider_status' => 'canceled', 'terminal_at' => now()])->save();
            if ($invoice->order->kind === 'renewal') {
                OrganizationCommercialAccount::query()->where('organization_id', $organizationId)->update(['bank_auto_invoice' => false]);
            }
        }, 3);
    }

    public function document(User $actor, int $organizationId, string $publicId): CommercialBankDocument
    {
        $this->access->organization($actor, $organizationId);

        return CommercialBankDocument::query()->where('organization_id', $organizationId)->where('public_id', $publicId)
            ->whereHas('invoice', fn ($invoice) => $invoice->where('organization_id', $organizationId))->firstOrFail();
    }
}
