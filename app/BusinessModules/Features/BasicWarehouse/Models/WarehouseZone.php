<?php

namespace App\BusinessModules\Features\BasicWarehouse\Models;

use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Модель зоны хранения на складе
 */
class WarehouseZone extends Model
{
    use HasFactory;

    protected $fillable = [
        'warehouse_id',
        'name',
        'code',
        'zone_type',
        'rack_number',
        'shelf_number',
        'cell_number',
        'capacity',
        'max_weight',
        'storage_conditions',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'capacity' => 'decimal:2',
        'max_weight' => 'decimal:2',
        'storage_conditions' => 'array',
        'is_active' => 'boolean',
    ];

    // Типы зон
    const TYPE_STORAGE = 'storage';
    const TYPE_RECEIVING = 'receiving';
    const TYPE_SHIPPING = 'shipping';
    const TYPE_QUARANTINE = 'quarantine';
    const TYPE_RETURNS = 'returns';

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(OrganizationWarehouse::class, 'warehouse_id');
    }

    /**
     * Получить полный адрес зоны
     */
    public function getFullAddressAttribute(): string
    {
        $parts = array_filter([
            $this->rack_number ? "Стеллаж {$this->rack_number}" : null,
            $this->shelf_number ? "Полка {$this->shelf_number}" : null,
            $this->cell_number ? "Ячейка {$this->cell_number}" : null,
        ]);

        return $parts ? implode(', ', $parts) : $this->name;
    }

    /**
     * Scope для активных зон
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope по типу зоны
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('zone_type', $type);
    }
}
