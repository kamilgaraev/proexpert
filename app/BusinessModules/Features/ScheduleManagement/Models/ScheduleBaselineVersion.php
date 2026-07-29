<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Models;

use Illuminate\Database\Eloquent\Model;

final class ScheduleBaselineVersion extends Model
{
    protected $table = 'schedule_baseline_versions';

    protected $guarded = [];

    protected $casts = [
        'captured_at' => 'immutable_datetime',
        'source_payload' => 'array',
    ];
}
