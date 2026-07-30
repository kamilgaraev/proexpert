<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\Models;

use App\BusinessModules\Features\Procurement\Reporting\Support\ImmutableReportingRecord;
use Illuminate\Database\Eloquent\Model;

final class ProcurementCycleOwnerExpectationVersion extends Model
{
    use ImmutableReportingRecord;

    protected $table = 'procurement_cycle_owner_expectation_versions';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'purchase_request_id' => 'integer',
            'purchase_request_line_id' => 'integer',
            'expectation_version' => 'integer',
            'dimensions' => 'array',
            'effective_from' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
        ];
    }
}
