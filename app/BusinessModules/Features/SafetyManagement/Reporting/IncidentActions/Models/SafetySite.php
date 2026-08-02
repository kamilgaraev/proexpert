<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Models;

use Illuminate\Database\Eloquent\Model;

final class SafetySite extends Model
{
    protected $table = 'safety_sites';

    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'active_from' => 'date',
        'active_until' => 'date',
    ];
}
