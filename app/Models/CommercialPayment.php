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
    ];

    protected $casts = [
        'amount_minor' => 'integer',
        'payment_method_saved' => 'boolean',
        'safe_response' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(CommercialOrder::class, 'commercial_order_id');
    }
}
