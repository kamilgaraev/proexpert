<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Models;

use Illuminate\Database\Eloquent\Model;

final class SafetyIncidentRow extends Model
{
    public $timestamps = false;

    protected $table = 'safety_incident_rows';

    protected $guarded = [];

    protected $casts = [
        'event_date' => 'immutable_date',
        'due_date' => 'immutable_date',
        'opening_flag' => 'boolean',
        'created_flag' => 'boolean',
        'reopened_flag' => 'boolean',
        'closed_flag' => 'boolean',
        'closing_flag' => 'boolean',
        'closure_verified' => 'boolean',
        'event_version' => 'integer',
        'closure_days' => 'integer',
    ];
}
