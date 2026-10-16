<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CommercialBankInvoice extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['status' => 'awaiting_payment', 'currency' => 'RUB', 'paid_minor' => 0, 'refunded_minor' => 0];

    protected $casts = ['snapshot' => 'array', 'amount_minor' => 'integer', 'paid_minor' => 'integer', 'refunded_minor' => 'integer', 'expires_at' => 'immutable_datetime', 'paid_at' => 'immutable_datetime', 'activated_at' => 'immutable_datetime', 'last_reminded_at' => 'immutable_datetime'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(CommercialOrder::class, 'commercial_order_id');
    }

    public function paymentProof(): HasOne
    {
        return $this->hasOne(CommercialBankPaymentProof::class, 'commercial_bank_invoice_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(CommercialPayment::class, 'commercial_payment_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(CommercialBankAllocation::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(CommercialBankDocument::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(CommercialBankRefund::class);
    }

    public function operations(): HasMany
    {
        return $this->hasMany(CommercialBankOperation::class, 'matched_invoice_id');
    }
}
