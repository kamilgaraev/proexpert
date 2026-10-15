<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommercialBankAllocation extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['amount_minor' => 'integer'];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CommercialBankInvoice::class, 'commercial_bank_invoice_id');
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(CommercialBankOperation::class, 'commercial_bank_operation_id');
    }
}
