<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstimateItemTotal extends Model
{
    use HasFactory;

    protected $fillable = [
        'estimate_item_id',
        'data_type',
        'caption',
        'quantity_for_one',
        'quantity_total',
        'for_one_curr',
        'total_curr',
        'total_base',
        'sort_order',
        'metadata',
    ];

    protected $casts = [
        'quantity_for_one' => 'decimal:4',
        'quantity_total' => 'decimal:4',
        'for_one_curr' => 'decimal:2',
        'total_curr' => 'decimal:2',
        'total_base' => 'decimal:2',
        'sort_order' => 'integer',
        'metadata' => 'array',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(EstimateItem::class, 'estimate_item_id');
    }

    public function scopeByDataType($query, string $dataType)
    {
        return $query->where('data_type', $dataType);
    }

    public function scopeByItem($query, int $itemId)
    {
        return $query->where('estimate_item_id', $itemId);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }

    /**
     * Получить итоги, сгруппированные по типу данных
     */
    public static function getByDataType(int $itemId): array
    {
        return static::where('estimate_item_id', $itemId)
            ->orderBy('sort_order')
            ->get()
            ->groupBy('data_type')
            ->toArray();
    }
}
