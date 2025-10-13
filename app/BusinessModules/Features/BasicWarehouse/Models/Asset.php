<?php

namespace App\BusinessModules\Features\BasicWarehouse\Models;

use App\Models\Material;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Модель Asset расширяет Material для работы со всеми типами активов
 * 
 * Поддерживаемые типы активов:
 * - materials (материалы)
 * - equipment (оборудование)
 * - tools (инструменты)
 * - furniture (мебель)
 * - consumables (расходники)
 * - structures (конструкции)
 */
class Asset extends Material
{
    /**
     * Указываем что используем ту же таблицу что и Material
     */
    protected $table = 'materials';
    /**
     * Типы активов
     */
    const TYPE_MATERIAL = 'material';
    const TYPE_EQUIPMENT = 'equipment';
    const TYPE_TOOL = 'tool';
    const TYPE_FURNITURE = 'furniture';
    const TYPE_CONSUMABLE = 'consumable';
    const TYPE_STRUCTURE = 'structure';

    /**
     * Все доступные типы активов
     */
    public static function getAssetTypes(): array
    {
        return [
            self::TYPE_MATERIAL => 'Материалы',
            self::TYPE_EQUIPMENT => 'Оборудование',
            self::TYPE_TOOL => 'Инструменты',
            self::TYPE_FURNITURE => 'Мебель',
            self::TYPE_CONSUMABLE => 'Расходники',
            self::TYPE_STRUCTURE => 'Конструкции',
        ];
    }

    /**
     * Получить тип актива из additional_properties
     */
    public function getAssetTypeAttribute(): string
    {
        return $this->additional_properties['asset_type'] ?? self::TYPE_MATERIAL;
    }

    /**
     * Установить тип актива в additional_properties
     */
    public function setAssetTypeAttribute(string $value): void
    {
        $properties = $this->additional_properties ?? [];
        $properties['asset_type'] = $value;
        $this->additional_properties = $properties;
    }

    /**
     * Получить категорию актива из additional_properties
     */
    public function getAssetCategoryAttribute(): ?string
    {
        return $this->additional_properties['asset_category'] ?? null;
    }

    /**
     * Установить категорию актива в additional_properties
     */
    public function setAssetCategoryAttribute(?string $value): void
    {
        $properties = $this->additional_properties ?? [];
        $properties['asset_category'] = $value;
        $this->additional_properties = $properties;
    }

    /**
     * Получить подкатегорию актива из additional_properties
     */
    public function getAssetSubcategoryAttribute(): ?string
    {
        return $this->additional_properties['asset_subcategory'] ?? null;
    }

    /**
     * Установить подкатегорию актива в additional_properties
     */
    public function setAssetSubcategoryAttribute(?string $value): void
    {
        $properties = $this->additional_properties ?? [];
        $properties['asset_subcategory'] = $value;
        $this->additional_properties = $properties;
    }

    /**
     * Получить динамические атрибуты актива
     */
    public function getAssetAttributesAttribute(): array
    {
        return $this->additional_properties['asset_attributes'] ?? [];
    }

    /**
     * Установить динамические атрибуты актива
     */
    public function setAssetAttributesAttribute(array $value): void
    {
        $properties = $this->additional_properties ?? [];
        $properties['asset_attributes'] = $value;
        $this->additional_properties = $properties;
    }

    /**
     * Получить остатки актива на складах
     */
    public function warehouseBalances(): HasMany
    {
        return $this->hasMany(WarehouseBalance::class, 'material_id');
    }

    /**
     * Scope для фильтрации по типу актива
     * Поддерживает PostgreSQL и MySQL
     */
    public function scopeOfType($query, string $type)
    {
        $driver = $query->getConnection()->getDriverName();
        
        if ($driver === 'pgsql') {
            return $query->whereRaw("additional_properties->>'asset_type' = ?", [$type]);
        } else {
            return $query->whereRaw("JSON_EXTRACT(additional_properties, '$.asset_type') = ?", [$type]);
        }
    }

    /**
     * Scope для фильтрации по категории
     * Поддерживает PostgreSQL и MySQL
     */
    public function scopeOfCategory($query, string $category)
    {
        $driver = $query->getConnection()->getDriverName();
        
        if ($driver === 'pgsql') {
            return $query->whereRaw("additional_properties->>'asset_category' = ?", [$category]);
        } else {
            return $query->whereRaw("JSON_EXTRACT(additional_properties, '$.asset_category') = ?", [$category]);
        }
    }

    /**
     * Проверка, является ли актив материалом
     */
    public function isMaterial(): bool
    {
        return $this->asset_type === self::TYPE_MATERIAL;
    }

    /**
     * Проверка, является ли актив оборудованием
     */
    public function isEquipment(): bool
    {
        return $this->asset_type === self::TYPE_EQUIPMENT;
    }

    /**
     * Проверка, является ли актив инструментом
     */
    public function isTool(): bool
    {
        return $this->asset_type === self::TYPE_TOOL;
    }

    /**
     * Получить общее количество на всех складах
     */
    public function getTotalWarehouseQuantity(): float
    {
        return $this->warehouseBalances()->sum('available_quantity');
    }

    /**
     * Получить общее количество зарезервированных активов
     */
    public function getTotalReservedQuantity(): float
    {
        return $this->warehouseBalances()->sum('reserved_quantity');
    }

    /**
     * Получить общее доступное количество (не зарезервировано)
     */
    public function getTotalAvailableQuantity(): float
    {
        return $this->getTotalWarehouseQuantity() - $this->getTotalReservedQuantity();
    }
}

