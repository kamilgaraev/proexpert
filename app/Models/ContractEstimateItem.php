<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractEstimateItem extends Model
{
    protected $fillable = [
        'contract_id',
        'estimate_id',
        'estimate_item_id',
        'quantity',
        'amount',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:8',
        'amount'   => 'decimal:2',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    public function estimateItem(): BelongsTo
    {
        return $this->belongsTo(EstimateItem::class);
    }
}
