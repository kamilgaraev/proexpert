<?php

namespace App\BusinessModules\Features\AdvancedWarehouse\Models;

use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\Models\Material;
use App\Models\Organization;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Модель правила автоматического пополнения
 */
class AutoReorderRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'warehouse_id',
        'material_id',
        'min_stock',
        'max_stock',
        'reorder_point',
        'reorder_quantity',
        'default_supplier_id',
        'is_active',
        'last_checked_at',
        'last_ordered_at',
        'notes',
    ];

    protected $casts = [
        'min_stock' => 'decimal:3',
        'max_stock' => 'decimal:3',
        'reorder_point' => 'decimal:3',
        'reorder_quantity' => 'decimal:3',
        'is_active' => 'boolean',
        'last_checked_at' => 'datetime',
        'last_ordered_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(OrganizationWarehouse::class, 'warehouse_id');
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function defaultSupplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'default_supplier_id');
    }

    /**
     * Scope для активных правил
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Проверить нужно ли пополнение
     */
    public function needsReorder(float $currentStock): bool
    {
        return $currentStock <= $this->reorder_point;
    }

    /**
     * Рассчитать количество для заказа
     */
    public function calculateOrderQuantity(float $currentStock): float
    {
        // Заказываем до максимального уровня или фиксированное количество
        $toMaxStock = $this->max_stock - $currentStock;
        
        return max($this->reorder_quantity, $toMaxStock);
    }
}

