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
        'command_payload' => 'array',
        'policy_definition' => 'array',
        'business_date' => 'immutable_date',
        'captured_at' => 'immutable_datetime',
        'frozen_at' => 'immutable_datetime',
        'available_at' => 'immutable_datetime',
        'claimed_at' => 'immutable_datetime',
        'started_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
        'dead_lettered_at' => 'immutable_datetime',
    ];
}
