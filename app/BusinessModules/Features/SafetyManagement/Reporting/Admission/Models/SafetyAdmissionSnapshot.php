<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Models;

use Illuminate\Database\Eloquent\Model;

final class SafetyAdmissionSnapshot extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'safety_admission_snapshots';

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'policy_version_ids' => 'array',
        'snapshot_date' => 'immutable_date',
        'source_watermark' => 'immutable_datetime',
        'sealed_at' => 'immutable_datetime',
        'source_ledger_binding' => 'array',
        'generated_at' => 'immutable_datetime',
        'stale_at' => 'immutable_datetime',
        'row_count' => 'integer',
        'evaluated_people' => 'integer',
        'admitted_people' => 'integer',
        'partial_people' => 'integer',
        'not_admitted_people' => 'integer',
        'blocker_count' => 'integer',
        'expiring_count' => 'integer',
        'unverified_count' => 'integer',
        'eligible_count' => 'integer',
        'projected_count' => 'integer',
        'gap_count' => 'integer',
        'unknown_count' => 'integer',
    ];
}
