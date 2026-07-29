<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\Models;

use Illuminate\Database\Eloquent\Model;

final class BaselineScheduleVarianceRecord extends Model
{
    public $timestamps = false;

    protected $table = 'baseline_schedule_variance_rows';

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'source_refs' => 'array',
    ];
}
