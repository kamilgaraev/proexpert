<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\Models;

use App\BusinessModules\Features\Procurement\Reporting\Support\ImmutableReportingRecord;
use Illuminate\Database\Eloquent\Model;

final class ProcurementCycleRow extends Model
{
    use ImmutableReportingRecord;

    protected $table = 'procurement_cycle_rows';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'purchase_request_id' => 'integer',
            'purchase_request_line_id' => 'integer',
            'project_id' => 'integer',
            'supplier_request_id' => 'integer',
            'supplier_proposal_version_id' => 'integer',
            'purchase_order_id' => 'integer',
            'purchase_receipt_id' => 'integer',
            'stage_started_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'cohort_date' => 'immutable_date',
            'outcome_cohort_date' => 'immutable_date',
            'cohort_mature' => 'boolean',
            'stage_timestamps' => 'array',
            'stage_duration_seconds' => 'array',
            'total_duration_seconds' => 'integer',
            'sla_numerator' => 'integer',
            'sla_denominator' => 'integer',
            'quality_warnings' => 'array',
        ];
    }
}
