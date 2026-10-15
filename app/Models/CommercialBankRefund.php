<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommercialBankRefund extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['status' => 'requested'];

    protected $casts = ['amount_minor' => 'integer', 'approved_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CommercialBankInvoice::class, 'commercial_bank_invoice_id');
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(CommercialBankOperation::class, 'commercial_bank_operation_id');
    }
}
