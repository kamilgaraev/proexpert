<?php

namespace App\BusinessModules\Features\BasicWarehouse\Models;

use App\Models\Organization;
use App\Models\Material;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Модель распределения материалов со склада по проектам
 * 
 * Позволяет отслеживать какое количество материала со склада
 * выделено под конкретный проект
 */
class WarehouseProjectAllocation extends Model
{
    protected $fillable = [
        'organization_id',
        'warehouse_id',
        'material_id',
        'project_id',
        'allocated_quantity',
        'allocated_by_user_id',
        'allocated_at',
        'notes',
    ];

    protected $casts = [
        'allocated_quantity' => 'decimal:3',
        'allocated_at' => 'datetime',
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
     * Получить материал
     */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    /**
     * Получить проект
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Получить пользователя, который выделил материал
     */
    public function allocatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'allocated_by_user_id');
    }
}

