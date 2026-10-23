<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectEventSeriesSegment extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'revision' => 'integer',
        'from_position' => 'integer',
        'anchor_date' => 'date',
        'until' => 'date',
        'enabled' => 'boolean',
        'template' => 'array',
        'interval' => 'array',
        'configuration' => 'array',
    ];
}
