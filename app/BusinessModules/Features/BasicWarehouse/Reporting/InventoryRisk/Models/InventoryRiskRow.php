<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Models;

use App\BusinessModules\Features\BasicWarehouse\Reporting\Support\ImmutableReportingRecord;
use Illuminate\Database\Eloquent\Model;

final class InventoryRiskRow extends Model
{
    use ImmutableReportingRecord;

    protected $table = 'inventory_risk_rows';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'warehouse_id' => 'integer',
            'project_id' => 'integer',
            'material_id' => 'integer',
            'balance_date' => 'immutable_date',
            'on_hand_value_minor' => 'integer',
            'consumption_value_minor' => 'integer',
            'stockout_at' => 'immutable_datetime',
            'demand_snapshot_id' => 'integer',
            'reorder_policy_version_id' => 'integer',
            'quality_warnings' => 'array',
            'inventory_event_ids' => 'array',
        ];
    }
}
