<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\Models;

use Illuminate\Database\Eloquent\Model;

final class ScheduleTaskStateVersion extends Model
{
    protected $guarded = [];

    protected $casts = [
        'effective_at' => 'immutable_datetime',
        'planned_start' => 'immutable_date',
        'planned_end' => 'immutable_date',
        'is_critical' => 'boolean',
    ];
}
