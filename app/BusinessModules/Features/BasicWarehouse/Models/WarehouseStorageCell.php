<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarehouseStorageCell extends Model
{
    use HasFactory;

    public const TYPE_STORAGE = 'storage';
    public const TYPE_PICKING = 'picking';
    public const TYPE_BUFFER = 'buffer';
    public const TYPE_RECEIVING = 'receiving';
    public const TYPE_SHIPPING = 'shipping';
    public const TYPE_QUARANTINE = 'quarantine';
    public const TYPE_RETURNS = 'returns';

    public const STATUS_AVAILABLE = 'available';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_MAINTENANCE = 'maintenance';
    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'organization_id',
        'warehouse_id',
        'zone_id',
        'name',
        'code',
        'cell_type',
        'status',
        'rack_number',
        'shelf_number',
        'bin_number',
        'capacity',
        'max_weight',
        'metadata',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'capacity' => 'decimal:3',
        'max_weight' => 'decimal:3',
        'metadata' => 'array',
        'is_active' => 'boolean',
    ];

    protected $appends = [
        'full_address',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(OrganizationWarehouse::class, 'warehouse_id');
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(WarehouseZone::class, 'zone_id');
    }

    public function getFullAddressAttribute(): string
    {
        $parts = array_filter([
            $this->zone?->code,
            $this->rack_number ? "R{$this->rack_number}" : null,
            $this->shelf_number ? "S{$this->shelf_number}" : null,
            $this->bin_number ? "B{$this->bin_number}" : null,
        ]);

        return $parts !== [] ? implode('-', $parts) : $this->code;
    }
}
