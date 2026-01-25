<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstimateItemWork extends Model
{
    use HasFactory;

    protected $fillable = [
        'estimate_item_id',
        'caption',
        'sort_order',
        'metadata',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'metadata' => 'array',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(EstimateItem::class, 'estimate_item_id');
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }

    public function scopeByItem($query, int $itemId)
    {
        return $query->where('estimate_item_id', $itemId);
    }
}
