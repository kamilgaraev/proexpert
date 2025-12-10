<?php

namespace App\BusinessModules\Features\Procurement\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Material;

/**
 * Модель позиции заказа поставщику
 * 
 * Хранит информацию о материалах в заказе:
 * количество, цены, единицы измерения
 */
class PurchaseOrderItem extends Model
{
    use HasFactory;

    protected $table = 'purchase_order_items';

    protected $fillable = [
        'purchase_order_id',
        'material_id',
        'material_name',
        'quantity',
        'unit',
        'unit_price',
        'total_price',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'total_price' => 'decimal:2',
        'metadata' => 'array',
    ];

    // ============================================
    // RELATIONSHIPS
    // ============================================

    /**
     * Заказ поставщику
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * Материал из каталога
     */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    // ============================================
    // ACCESSORS
    // ============================================

    /**
     * Автоматический расчет total_price
     */
    public function calculateTotalPrice(): void
    {
        $this->total_price = $this->quantity * $this->unit_price;
    }

    // ============================================
    // BOOT
    // ============================================

    protected static function boot()
    {
        parent::boot();

        // Автоматически рассчитываем total_price при сохранении
        static::saving(function ($item) {
            if ($item->isDirty(['quantity', 'unit_price'])) {
                $item->calculateTotalPrice();
            }
        });
    }
}

