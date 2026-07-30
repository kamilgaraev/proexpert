<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Models;

use Illuminate\Database\Eloquent\Model;

final class QualityDefectFlowSnapshot extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'quality_defect_flow_snapshots';

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'policy_version_ids' => 'array',
        'as_of' => 'immutable_datetime',
        'source_watermark' => 'immutable_datetime',
        'source_ledger_binding' => 'array',
        'generated_at' => 'immutable_datetime',
        'stale_at' => 'immutable_datetime',
        'row_count' => 'integer',
        'opening_count' => 'integer',
        'created_count' => 'integer',
        'reopened_count' => 'integer',
        'closed_count' => 'integer',
        'closing_count' => 'integer',
        'due_count' => 'integer',
        'overdue_count' => 'integer',
        'overdue_pct' => 'decimal:4',
        'mature_cohort_count' => 'integer',
        'first_pass_count' => 'integer',
        'mature_reopened_count' => 'integer',
        'reopen_rate' => 'decimal:4',
        'first_pass_yield' => 'decimal:4',
        'eligible_count' => 'integer',
        'projected_count' => 'integer',
        'gap_count' => 'integer',
        'unknown_count' => 'integer',
    ];
}
