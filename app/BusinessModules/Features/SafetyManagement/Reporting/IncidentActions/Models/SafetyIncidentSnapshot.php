<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Models;

use Illuminate\Database\Eloquent\Model;

final class SafetyIncidentSnapshot extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'safety_incident_snapshots';

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'policy_version_ids' => 'array',
        'as_of' => 'immutable_datetime',
        'source_watermark' => 'immutable_datetime',
        'source_ledger_binding' => 'array',
        'generated_at' => 'immutable_datetime',
        'stale_at' => 'immutable_datetime',
        'exposure_hours' => 'decimal:4',
        'incident_frequency' => 'decimal:4',
        'exposure_complete' => 'boolean',
        'row_count' => 'integer',
        'opening_backlog_count' => 'integer',
        'closing_backlog_count' => 'integer',
        'incident_count' => 'integer',
        'violation_count' => 'integer',
        'action_due_count' => 'integer',
        'action_overdue_count' => 'integer',
        'action_closed_on_time_count' => 'integer',
        'eligible_count' => 'integer',
        'projected_count' => 'integer',
        'gap_count' => 'integer',
        'unknown_count' => 'integer',
    ];
}
