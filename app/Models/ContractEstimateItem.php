<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractEstimateItem extends Model
{
    protected $fillable = [
        'contract_id',
        'estimate_id',
        'estimate_item_id',
        'quantity',
        'amount',
        'amount_without_vat',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:8',
        'amount' => 'decimal:2',
        'amount_without_vat' => 'decimal:2',
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

    public function scopeCountedInCoverage(Builder $query): Builder
    {
        return $query->whereHas('estimateItem', static function (Builder $items): void {
            $items->where(static function (Builder $accounted): void {
                $accounted->where('is_not_accounted', false)->orWhereNull('is_not_accounted');
            });
        });
    }
}
