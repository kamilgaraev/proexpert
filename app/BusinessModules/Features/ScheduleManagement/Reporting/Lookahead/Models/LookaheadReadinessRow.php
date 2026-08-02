<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Models;

use Illuminate\Database\Eloquent\Model;

final class LookaheadReadinessRow extends Model
{
    public $timestamps = false;

    protected $table = 'lookahead_reporting_rows';

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'source_refs' => 'array',
    ];
}
