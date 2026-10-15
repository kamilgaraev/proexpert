<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommercialBankDocument extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['status' => 'pending'];

    protected $casts = ['snapshot' => 'array', 'issued_at' => 'immutable_datetime', 'notified_at' => 'immutable_datetime'];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CommercialBankInvoice::class, 'commercial_bank_invoice_id');
    }
}
