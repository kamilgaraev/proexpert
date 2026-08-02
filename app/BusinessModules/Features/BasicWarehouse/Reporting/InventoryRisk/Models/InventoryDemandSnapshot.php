<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Models;

use App\BusinessModules\Features\BasicWarehouse\Reporting\Support\ImmutableReportingRecord;
use Illuminate\Database\Eloquent\Model;

final class InventoryDemandSnapshot extends Model
{
    use ImmutableReportingRecord;

    protected $table = 'inventory_demand_snapshots';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'warehouse_id' => 'integer',
            'project_id' => 'integer',
            'material_id' => 'integer',
            'horizon_days' => 'integer',
            'approved_at' => 'immutable_datetime',
            'effective_from' => 'immutable_datetime',
            'effective_to' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
