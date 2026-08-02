<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Models;

use App\BusinessModules\Features\BasicWarehouse\Reporting\Support\ImmutableReportingRecord;
use Illuminate\Database\Eloquent\Model;

final class WarehouseDailyBalanceRow extends Model
{
    use ImmutableReportingRecord;

    protected $table = 'warehouse_daily_balance_rows';

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
            'unit_price_minor' => 'integer',
            'quality_warnings' => 'array',
        ];
    }
}
