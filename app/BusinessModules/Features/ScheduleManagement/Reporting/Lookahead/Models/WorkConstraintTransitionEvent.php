<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Models;

use Illuminate\Database\Eloquent\Model;

final class WorkConstraintTransitionEvent extends Model
{
    public $timestamps = false;

    protected $table = 'work_constraint_transition_events';

    protected $guarded = [];

    protected $casts = [
        'waiver_until' => 'immutable_datetime',
        'occurred_at' => 'immutable_datetime',
        'evidence_refs' => 'array',
    ];
}
