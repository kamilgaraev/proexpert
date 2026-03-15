<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WarehouseLogisticUnit extends Model
{
    use HasFactory;

    public const TYPE_BOX = 'box';
    public const TYPE_PALLET = 'pallet';
    public const TYPE_CONTAINER = 'container';
    public const TYPE_BUNDLE = 'bundle';
    public const TYPE_CART = 'cart';
    public const TYPE_KIT = 'kit';
    public const TYPE_CUSTOM = 'custom';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_AVAILABLE = 'available';
    public const STATUS_SEALED = 'sealed';
    public const STATUS_IN_TRANSIT = 'in_transit';
    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'organization_id',
        'warehouse_id',
        'zone_id',
        'cell_id',
        'parent_unit_id',
        'name',
        'code',
        'unit_type',
        'status',
        'capacity',
        'current_load',
        'gross_weight',
        'volume',
        'last_scanned_at',
        'metadata',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'capacity' => 'decimal:3',
        'current_load' => 'decimal:3',
        'gross_weight' => 'decimal:3',
        'volume' => 'decimal:3',
        'last_scanned_at' => 'datetime',
        'metadata' => 'array',
        'is_active' => 'boolean',
    ];

    protected $appends = [
        'storage_address',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(OrganizationWarehouse::class, 'warehouse_id');
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(WarehouseZone::class, 'zone_id');
    }

    public function cell(): BelongsTo
    {
        return $this->belongsTo(WarehouseStorageCell::class, 'cell_id');
    }

    public function parentUnit(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_unit_id');
    }

    public function childUnits(): HasMany
    {
        return $this->hasMany(self::class, 'parent_unit_id');
    }

    public function identifiers(): HasMany
    {
        return $this->hasMany(WarehouseIdentifier::class, 'entity_id')
            ->where('entity_type', 'logistic_unit');
    }

    public function scanEvents(): HasMany
    {
        return $this->hasMany(WarehouseScanEvent::class, 'logistic_unit_id');
    }

    public function getStorageAddressAttribute(): ?string
    {
        if ($this->cell?->full_address) {
            return $this->cell->full_address;
        }

        if ($this->zone?->code) {
            return $this->zone->code;
        }

        return null;
    }
}
