<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommercialRefund extends Model
{
    protected $fillable = [
        'commercial_order_id', 'commercial_payment_id', 'provider', 'provider_refund_id',
        'provider_status', 'amount_minor', 'currency', 'safe_response',
        'provider_idempotency_key', 'request_fingerprint', 'reconciliation_required', 'last_reconciled_at',
    ];

    protected $casts = [
        'amount_minor' => 'integer',
        'safe_response' => 'array',
        'reconciliation_required' => 'boolean',
        'last_reconciled_at' => 'immutable_datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(CommercialOrder::class, 'commercial_order_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(CommercialPayment::class, 'commercial_payment_id');
    }
}
