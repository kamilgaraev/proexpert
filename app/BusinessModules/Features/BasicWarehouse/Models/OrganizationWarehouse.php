<?php

namespace App\BusinessModules\Features\BasicWarehouse\Models;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Модель склада организации
 * 
 * BasicWarehouse: 1 центральный склад
 * AdvancedWarehouse: до 20 складов с зонами хранения
 */
class OrganizationWarehouse extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'name',
        'code',
        'address',
        'description',
        'warehouse_type',
        'is_main',
        'is_active',
        'settings',
        'contact_person',
        'contact_phone',
        'working_hours',
        'storage_conditions',
    ];

    protected $casts = [
        'is_main' => 'boolean',
        'is_active' => 'boolean',
        'settings' => 'array',
        'storage_conditions' => 'array',
    ];

    /**
     * Типы складов
     */
    const TYPE_CENTRAL = 'central';
    const TYPE_PROJECT = 'project';
    const TYPE_EXTERNAL = 'external';

    /**
     * Получить организацию склада
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Получить остатки на складе
     */
    public function balances(): HasMany
    {
        return $this->hasMany(WarehouseBalance::class, 'warehouse_id');
    }

    /**
     * Получить зоны хранения
     */
    public function zones(): HasMany
    {
        return $this->hasMany(\App\BusinessModules\Features\BasicWarehouse\Models\WarehouseZone::class, 'warehouse_id');
    }

    /**
     * Scope для основного склада
     */
    public function scopeMain($query)
    {
        return $query->where('is_main', true);
    }

    /**
     * Scope для активных складов
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope по типу склада
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('warehouse_type', $type);
    }

    /**
     * Получить общую стоимость активов на складе
     */
    public function getTotalValue(): float
    {
        return $this->balances()
            ->join('materials', 'warehouse_balances.material_id', '=', 'materials.id')
            ->selectRaw('SUM(warehouse_balances.available_quantity * warehouse_balances.average_price) as total')
            ->value('total') ?? 0.0;
    }

    /**
     * Получить количество уникальных позиций на складе
     */
    public function getUniqueItemsCount(): int
    {
        return $this->balances()
            ->where('available_quantity', '>', 0)
            ->count();
    }

    /**
     * Получить остаток конкретного актива на складе
     */
    public function getAssetBalance(int $materialId): ?WarehouseBalance
    {
        return $this->balances()
            ->where('material_id', $materialId)
            ->first();
    }

    /**
     * Проверка, является ли склад центральным
     */
    public function isMain(): bool
    {
        return $this->is_main === true;
    }

    /**
     * Проверка, активен ли склад
     */
    public function isActive(): bool
    {
        return $this->is_active === true;
    }

    /**
     * Получить настройку склада
     */
    public function getSetting(string $key, $default = null)
    {
        return $this->settings[$key] ?? $default;
    }

    /**
     * Установить настройку склада
     */
    public function setSetting(string $key, $value): void
    {
        $settings = $this->settings ?? [];
        $settings[$key] = $value;
        $this->settings = $settings;
        $this->save();
    }
}

