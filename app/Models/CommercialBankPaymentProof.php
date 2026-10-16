<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommercialBankPaymentProof extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['storage_path', 'sha256'];

    protected $casts = ['package_slugs' => 'array', 'resource_addons' => 'array',
        'submitted_at' => 'immutable_datetime', 'temporary_expires_at' => 'immutable_datetime', 'resolved_at' => 'immutable_datetime'];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CommercialBankInvoice::class, 'commercial_bank_invoice_id');
    }

    public function scopeTemporary(Builder $query): Builder
    {
        return $query->where('status', 'pending')->where('submitted_at', '<=', now())->where('temporary_expires_at', '>', now())
            ->whereHas('invoice', fn (Builder $invoice) => $invoice
                ->whereColumn('commercial_bank_invoices.organization_id', 'commercial_bank_payment_proofs.organization_id')
                ->whereIn('status', ['awaiting_payment', 'partially_paid'])->whereNull('paid_at')
                ->whereHas('order', fn (Builder $order) => $order->where('status', 'pending_payment')
                    ->whereColumn('commercial_orders.commercial_account_id', 'commercial_bank_payment_proofs.commercial_account_id')));
    }
}
