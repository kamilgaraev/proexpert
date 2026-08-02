<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Models;

use Illuminate\Database\Eloquent\Model;

final class SafetyExposureDay extends Model
{
    public $timestamps = false;

    protected $table = 'safety_exposure_days';

    protected $guarded = [];

    protected $casts = [
        'exposure_date' => 'immutable_date',
        'exposure_hours' => 'decimal:4',
        'person_shifts' => 'integer',
        'complete' => 'boolean',
        'projected_at' => 'immutable_datetime',
    ];
}
