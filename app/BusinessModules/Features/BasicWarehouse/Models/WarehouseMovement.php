<?php

namespace App\BusinessModules\Features\BasicWarehouse\Models;

use App\Models\Material;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Модель движения активов на складе
 */
class WarehouseMovement extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'warehouse_id',
        'material_id',
        'movement_type',
        'quantity',
        'price',
        'from_warehouse_id',
        'to_warehouse_id',
        'project_id',
        'user_id',
        'document_number',
        'reason',
        'metadata',
        'movement_date',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'price' => 'decimal:2',
        'metadata' => 'array',
        'movement_date' => 'datetime',
    ];

    // Типы движений
    const TYPE_RECEIPT = 'receipt';
    const TYPE_WRITE_OFF = 'write_off';
    const TYPE_TRANSFER_IN = 'transfer_in';
    const TYPE_TRANSFER_OUT = 'transfer_out';
    const TYPE_ADJUSTMENT = 'adjustment';
    const TYPE_RETURN = 'return';

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

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(OrganizationWarehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(OrganizationWarehouse::class, 'to_warehouse_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope для фильтрации по типу движения
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('movement_type', $type);
    }

    /**
     * Scope для фильтрации по датам
     */
    public function scopeBetweenDates($query, $dateFrom, $dateTo)
    {
        return $query->whereBetween('movement_date', [$dateFrom, $dateTo]);
    }
}

