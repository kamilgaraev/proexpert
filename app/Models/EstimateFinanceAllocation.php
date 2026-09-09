<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EstimateFinanceAllocation extends Model
{
    public function contract(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    protected $guarded = ['id'];

    protected $casts = [
        'quantity' => 'decimal:8',
        'unit_price' => 'decimal:8',
        'amount_without_vat' => 'decimal:2',
        'amount_with_vat' => 'decimal:2',
        'legacy_amount' => 'decimal:2',
        'vat_rate' => 'decimal:4',
        'composition_confirmed' => 'boolean',
        'estimate_snapshot' => 'array',
    ];
}
