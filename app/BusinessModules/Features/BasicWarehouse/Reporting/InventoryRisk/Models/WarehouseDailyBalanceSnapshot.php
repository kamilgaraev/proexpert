<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Models;

use App\BusinessModules\Features\BasicWarehouse\Reporting\Support\ImmutableReportingRecord;
use Illuminate\Database\Eloquent\Model;

final class WarehouseDailyBalanceSnapshot extends Model
{
    use ImmutableReportingRecord;

    protected $table = 'warehouse_daily_balance_snapshots';

    protected $guarded = [];

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'row_count' => 'integer',
            'gap_count' => 'integer',
            'generated_at' => 'immutable_datetime',
        ];
    }
}
