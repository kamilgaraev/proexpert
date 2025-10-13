<?php

namespace App\BusinessModules\Features\BasicWarehouse\Models;

use App\Models\Material;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Модель остатков активов на складе
 * 
 * Хранит информацию о количестве и стоимости каждого актива на конкретном складе
 */
class WarehouseBalance extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'warehouse_id',
        'material_id',
        'available_quantity',
        'reserved_quantity',
        'average_price',
        'min_stock_level',
        'max_stock_level',
        'location_code',
        'batch_number',
        'serial_number',
        'expiry_date',
        'last_movement_at',
    ];

    protected $casts = [
        'available_quantity' => 'decimal:3',
        'reserved_quantity' => 'decimal:3',
        'average_price' => 'decimal:2',
        'min_stock_level' => 'decimal:3',
        'max_stock_level' => 'decimal:3',
        'expiry_date' => 'date',
        'last_movement_at' => 'datetime',
    ];

    /**
     * Отключаем timestamps для быстрых обновлений
     */
    public $timestamps = false;

    /**
     * Получить организацию
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Получить склад
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(OrganizationWarehouse::class, 'warehouse_id');
    }

    /**
     * Получить актив (материал)
     */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    /**
     * Получить актив через связь Asset
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'material_id');
    }

    /**
     * Получить распределения по проектам
     */
    public function projectAllocations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(WarehouseProjectAllocation::class, 'warehouse_id', 'warehouse_id')
            ->where('material_id', $this->material_id);
    }

    /**
     * Scope для низких остатков
     */
    public function scopeLowStock($query)
    {
        return $query->whereColumn('available_quantity', '<=', 'min_stock_level')
            ->where('min_stock_level', '>', 0);
    }

    /**
     * Scope для активов требующих пополнения
     */
    public function scopeNeedsReorder($query)
    {
        return $query->whereColumn('available_quantity', '<', 'min_stock_level')
            ->where('min_stock_level', '>', 0);
    }

    /**
     * Scope для избыточных остатков
     */
    public function scopeOverstock($query)
    {
        return $query->whereColumn('available_quantity', '>', 'max_stock_level')
            ->where('max_stock_level', '>', 0);
    }

    /**
     * Scope для активов с истекающим сроком годности
     */
    public function scopeExpiringSoon($query, int $days = 30)
    {
        return $query->whereNotNull('expiry_date')
            ->whereBetween('expiry_date', [now(), now()->addDays($days)]);
    }

    /**
     * Получить общее количество (доступное + зарезервированное)
     */
    public function getTotalQuantityAttribute(): float
    {
        return (float)$this->available_quantity + (float)$this->reserved_quantity;
    }

    /**
     * Получить стоимость доступных остатков
     */
    public function getAvailableValueAttribute(): float
    {
        return (float)$this->available_quantity * (float)$this->average_price;
    }

    /**
     * Получить общую стоимость остатков
     */
    public function getTotalValueAttribute(): float
    {
        return $this->total_quantity * (float)$this->average_price;
    }

    /**
     * Проверка на низкий остаток
     */
    public function isLowStock(): bool
    {
        if ($this->min_stock_level <= 0) {
            return false;
        }

        return $this->available_quantity <= $this->min_stock_level;
    }

    /**
     * Проверка требуется ли пополнение
     */
    public function needsReorder(): bool
    {
        if ($this->min_stock_level <= 0) {
            return false;
        }

        return $this->available_quantity < $this->min_stock_level;
    }

    /**
     * Проверка на избыток
     */
    public function isOverstock(): bool
    {
        if ($this->max_stock_level <= 0) {
            return false;
        }

        return $this->available_quantity > $this->max_stock_level;
    }

    /**
     * Проверка истекает ли срок годности
     */
    public function isExpiringSoon(int $days = 30): bool
    {
        if (!$this->expiry_date) {
            return false;
        }

        return $this->expiry_date->isBetween(now(), now()->addDays($days));
    }

    /**
     * Увеличить доступное количество
     */
    public function increaseQuantity(float $quantity, ?float $price = null): void
    {
        $this->available_quantity += $quantity;

        // Пересчет средней цены при поступлении
        if ($price !== null && $price > 0) {
            $oldValue = $this->available_quantity * $this->average_price;
            $newValue = $quantity * $price;
            $totalQuantity = $this->available_quantity + $quantity;

            if ($totalQuantity > 0) {
                $this->average_price = ($oldValue + $newValue) / $totalQuantity;
            }
        }

        $this->last_movement_at = now();
        $this->save();
    }

    /**
     * Уменьшить доступное количество
     */
    public function decreaseQuantity(float $quantity): void
    {
        if ($quantity > $this->available_quantity) {
            throw new \InvalidArgumentException(
                "Недостаточно активов на складе. Доступно: {$this->available_quantity}, запрошено: {$quantity}"
            );
        }

        $this->available_quantity -= $quantity;
        $this->last_movement_at = now();
        $this->save();
    }

    /**
     * Зарезервировать количество
     */
    public function reserve(float $quantity): void
    {
        if ($quantity > $this->available_quantity) {
            throw new \InvalidArgumentException(
                "Недостаточно активов для резервирования. Доступно: {$this->available_quantity}, запрошено: {$quantity}"
            );
        }

        $this->available_quantity -= $quantity;
        $this->reserved_quantity += $quantity;
        $this->last_movement_at = now();
        $this->save();
    }

    /**
     * Снять резервирование
     */
    public function unreserve(float $quantity): void
    {
        if ($quantity > $this->reserved_quantity) {
            throw new \InvalidArgumentException(
                "Недостаточно зарезервированных активов. Зарезервировано: {$this->reserved_quantity}, запрошено: {$quantity}"
            );
        }

        $this->reserved_quantity -= $quantity;
        $this->available_quantity += $quantity;
        $this->last_movement_at = now();
        $this->save();
    }

    /**
     * Списать зарезервированные активы
     */
    public function writeOffReserved(float $quantity): void
    {
        if ($quantity > $this->reserved_quantity) {
            throw new \InvalidArgumentException(
                "Недостаточно зарезервированных активов для списания. Зарезервировано: {$this->reserved_quantity}, запрошено: {$quantity}"
            );
        }

        $this->reserved_quantity -= $quantity;
        $this->last_movement_at = now();
        $this->save();
    }
}

