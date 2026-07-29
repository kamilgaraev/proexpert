<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Models;

use Illuminate\Database\Eloquent\Model;

final class SafetyAdmissionRow extends Model
{
    public $timestamps = false;

    protected $table = 'safety_admission_rows';

    protected $guarded = [];

    protected $casts = [
        'snapshot_date' => 'immutable_date',
        'mandatory' => 'boolean',
        'blocked' => 'boolean',
        'verified' => 'boolean',
        'valid_until' => 'immutable_date',
        'evidence_id' => 'integer',
        'medical_details' => 'array',
        'blocker_codes' => 'array',
    ];
}
