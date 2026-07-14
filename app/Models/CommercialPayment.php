<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommercialPayment extends Model
{
    protected $fillable = [
        'commercial_order_id', 'provider', 'provider_payment_id', 'provider_status',
        'amount_minor', 'currency', 'provider_idempotency_key', 'confirmation_url',
        'payment_method_id', 'payment_method_saved', 'safe_response',
        'refunded_amount_minor',
        'commercial_renewal_cycle_id', 'role', 'attempt_number', 'terminal_failure_reason',
        'failure_category', 'attempted_at', 'terminal_at',
        'reconciliation_required', 'last_reconciled_at',
    ];

    protected $casts = [
        'amount_minor' => 'integer',
        'payment_method_saved' => 'boolean',
        'safe_response' => 'array',
        'refunded_amount_minor' => 'integer',
        'attempt_number' => 'integer',
        'attempted_at' => 'immutable_datetime',
        'terminal_at' => 'immutable_datetime',
        'reconciliation_required' => 'boolean',
        'last_reconciled_at' => 'immutable_datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(CommercialOrder::class, 'commercial_order_id');
    }
}
