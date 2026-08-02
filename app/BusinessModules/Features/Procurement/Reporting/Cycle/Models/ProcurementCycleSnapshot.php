<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\Models;

use App\BusinessModules\Features\Procurement\Reporting\Support\ImmutableReportingRecord;
use Illuminate\Database\Eloquent\Model;

final class ProcurementCycleSnapshot extends Model
{
    use ImmutableReportingRecord;

    protected $table = 'procurement_cycle_snapshots';

    protected $guarded = [];

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'policy_version_id' => 'integer',
            'row_count' => 'integer',
            'eligible_count' => 'integer',
            'sla_numerator' => 'integer',
            'gap_count' => 'integer',
            'totals' => 'array',
            'generated_at' => 'immutable_datetime',
            'stale_at' => 'immutable_datetime',
        ];
    }
}
