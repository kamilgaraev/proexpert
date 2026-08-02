<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Models;

use Illuminate\Database\Eloquent\Model;

final class ProjectControlBaselineVersion extends Model
{
    protected $table = 'project_control_baseline_versions';

    protected $guarded = [];

    protected $casts = [
        'approved_at' => 'immutable_datetime',
        'source_payload' => 'array',
    ];
}
