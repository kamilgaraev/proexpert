<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Models;

use Illuminate\Database\Eloquent\Model;

final class WorkforceCapacityCaptureRequestRecord extends Model
{
    protected $table = 'workforce_capacity_capture_requests';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'started_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
    ];
}
