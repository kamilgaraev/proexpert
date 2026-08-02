<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Models;

use App\BusinessModules\Features\BasicWarehouse\Reporting\Support\ImmutableReportingRecord;
use Illuminate\Database\Eloquent\Model;

final class WarehouseInventoryEvent extends Model
{
    use ImmutableReportingRecord;

    public const EVENT_TYPES = [
        'receipt',
        'issue',
        'transfer_in',
        'transfer_out',
        'return',
        'adjustment',
        'reservation',
        'unreservation',
        'reserved_issue',
    ];

    protected $table = 'warehouse_inventory_events';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'warehouse_id' => 'integer',
            'project_id' => 'integer',
            'material_id' => 'integer',
            'source_movement_id' => 'integer',
            'source_version' => 'integer',
            'unit_price_minor' => 'integer',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'source_refs' => 'array',
        ];
    }
}
