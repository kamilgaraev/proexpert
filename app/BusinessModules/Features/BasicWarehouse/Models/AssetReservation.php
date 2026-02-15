<?php

namespace App\BusinessModules\Features\BasicWarehouse\Models;

use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\Models\Material;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Модель резервирования активов
 */
class AssetReservation extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'warehouse_id',
        'material_id',
        'quantity',
        'project_id',
        'reserved_by',
        'status',
        'reserved_at',
        'expires_at',
        'fulfilled_at',
        'cancelled_at',
        'reason',
        'metadata',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'metadata' => 'array',
        'reserved_at' => 'datetime',
        'expires_at' => 'datetime',
        'fulfilled_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    // Статусы
    const STATUS_ACTIVE = 'active';
    const STATUS_FULFILLED = 'fulfilled';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_EXPIRED = 'expired';

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

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function reservedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reserved_by');
    }

    /**
     * Scope для активных резерваций
     */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->where('expires_at', '>', now());
    }

    /**
     * Scope для истекших резерваций
     */
    public function scopeExpired($query)
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->where('expires_at', '<=', now());
    }

    /**
     * Проверить истекла ли резервация
     */
    public function isExpired(): bool
    {
        return $this->status === self::STATUS_ACTIVE && $this->expires_at->isPast();
    }
}
