<?php

namespace App\BusinessModules\Features\BasicWarehouse\Models;

use App\Models\Material;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Модель позиции акта инвентаризации
 */
class InventoryActItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'inventory_act_id',
        'material_id',
        'expected_quantity',
        'actual_quantity',
        'difference',
        'unit_price',
        'total_value',
        'location_code',
        'batch_number',
        'notes',
    ];

    protected $casts = [
        'expected_quantity' => 'decimal:3',
        'actual_quantity' => 'decimal:3',
        'difference' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'total_value' => 'decimal:2',
    ];

    public function inventoryAct(): BelongsTo
    {
        return $this->belongsTo(InventoryAct::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    /**
     * Рассчитать разницу
     */
    public function calculateDifference(): void
    {
        if ($this->actual_quantity !== null) {
            $this->difference = $this->actual_quantity - $this->expected_quantity;
            $this->total_value = $this->difference * $this->unit_price;
        }
    }

    /**
     * Проверить наличие расхождения
     */
    public function hasDiscrepancy(): bool
    {
        return $this->difference !== null && abs((float)$this->difference) > 0.001;
    }
}

