<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Models;

use Illuminate\Database\Eloquent\Model;

final class SafetyIncidentPolicyVersion extends Model
{
    protected $table = 'safety_incident_policy_versions';

    protected $guarded = [];

    protected $casts = [
        'effective_from' => 'immutable_date',
        'effective_until' => 'immutable_date',
        'qualifying_incident_types' => 'array',
        'terminal_statuses' => 'array',
        'closure_evidence_required' => 'boolean',
        'frequency_multiplier' => 'integer',
    ];
}
