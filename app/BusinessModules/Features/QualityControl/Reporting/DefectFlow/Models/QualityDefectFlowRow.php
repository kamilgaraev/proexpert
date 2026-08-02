<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Models;

use Illuminate\Database\Eloquent\Model;

final class QualityDefectFlowRow extends Model
{
    public $timestamps = false;

    protected $table = 'quality_defect_flow_rows';

    protected $guarded = [];

    protected $casts = [
        'cohort_date' => 'immutable_date',
        'due_date' => 'immutable_date',
        'opening_flag' => 'boolean',
        'created_flag' => 'boolean',
        'reopened_flag' => 'boolean',
        'closed_flag' => 'boolean',
        'closing_flag' => 'boolean',
        'cohort_eligible' => 'boolean',
        'event_version' => 'integer',
        'cycle_days' => 'integer',
        'evidence_refs' => 'array',
    ];
}
