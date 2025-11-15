<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstimatePositionPriceHistory extends Model
{
    protected $table = 'estimate_position_price_history';

    public $timestamps = false;

    protected $fillable = [
        'catalog_item_id',
        'user_id',
        'old_price',
        'new_price',
        'change_reason',
        'changed_at',
        'metadata',
    ];

    protected $casts = [
        'old_price' => 'decimal:2',
        'new_price' => 'decimal:2',
        'changed_at' => 'datetime',
        'metadata' => 'array',
    ];

    // ============================================
    // RELATIONSHIPS
    // ============================================

    /**
     * Позиция справочника
     */
    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(EstimatePositionCatalog::class, 'catalog_item_id');
    }

    /**
     * Пользователь, изменивший цену
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ============================================
    // METHODS
    // ============================================

    /**
     * Получить изменение цены в процентах
     */
    public function getPriceChangePercent(): float
    {
        if ($this->old_price == 0) {
            return 0;
        }

        return (($this->new_price - $this->old_price) / $this->old_price) * 100;
    }

    /**
     * Получить абсолютное изменение цены
     */
    public function getPriceChangeAbsolute(): float
    {
        return $this->new_price - $this->old_price;
    }

    /**
     * Проверить, является ли изменение увеличением цены
     */
    public function isPriceIncrease(): bool
    {
        return $this->new_price > $this->old_price;
    }

    /**
     * Проверить, является ли изменение уменьшением цены
     */
    public function isPriceDecrease(): bool
    {
        return $this->new_price < $this->old_price;
    }
}

