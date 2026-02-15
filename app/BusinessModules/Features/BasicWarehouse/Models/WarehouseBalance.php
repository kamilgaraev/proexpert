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

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'warehouse_id',
        'material_id',
        'available_quantity',
        'reserved_quantity',
        'unit_price',
        'min_stock_level',
        'max_stock_level',
        'location_code',
        'batch_number',
        'serial_number',
        'expiry_date',
        'last_movement_at',
        'created_at',
    ];

    protected $casts = [
        'available_quantity' => 'decimal:3',
        'reserved_quantity' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'min_stock_level' => 'decimal:3',
        'max_stock_level' => 'decimal:3',
        'expiry_date' => 'date',
        'last_movement_at' => 'datetime',
        'created_at' => 'datetime',
    ];

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
        return (float)$this->available_quantity * (float)$this->unit_price;
    }

    /**
     * Получить общую стоимость остатков
     */
    public function getTotalValueAttribute(): float
    {
        return $this->total_quantity * (float)$this->unit_price;
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

    /**
     * Получить количество материала, распределенного по проектам
     * (сколько уже "зарезервировано" за проектами)
     */
    public function getAllocatedQuantity(): float
    {
        return (float)WarehouseProjectAllocation::where('warehouse_id', $this->warehouse_id)
            ->where('material_id', $this->material_id)
            ->sum('allocated_quantity');
    }

    /**
     * Получить количество доступное для распределения
     * (доступное на складе минус уже распределенное по проектам)
     */
    public function getAvailableForAllocation(): float
    {
        $allocated = $this->getAllocatedQuantity();
        return max(0, (float)$this->available_quantity - $allocated);
    }

    /**
     * Проверить, можно ли распределить указанное количество
     */
    public function canAllocate(float $quantity): bool
    {
        return $quantity <= $this->getAvailableForAllocation();
    }

    /**
     * Проверить, достаточно ли материала для распределения
     * (с подробной информацией для ошибки)
     */
    public function checkAllocationAvailability(float $requestedQuantity): array
    {
        $allocated = $this->getAllocatedQuantity();
        $availableForAllocation = $this->getAvailableForAllocation();

        return [
            'can_allocate' => $requestedQuantity <= $availableForAllocation,
            'warehouse_quantity' => (float)$this->available_quantity,
            'already_allocated' => $allocated,
            'available_for_allocation' => $availableForAllocation,
            'requested_quantity' => $requestedQuantity,
            'shortage' => max(0, $requestedQuantity - $availableForAllocation),
        ];
    }
    /**
     * Флаг, указывающий, что это виртуальный (агрегированный) объект
     * и его нельзя сохранять в БД
     */
    public $isVirtual = false;

    /**
     * Переопределяем сохранение для защиты от записи виртуальных объектов
     */
    public function save(array $options = []): bool
    {
        if ($this->isVirtual) {
            throw new \RuntimeException("Попытка сохранить виртуальный (агрегированный) объект WarehouseBalance. Это приведет к повреждению данных партионного учета.");
        }
        return parent::save($options);
    }
}

