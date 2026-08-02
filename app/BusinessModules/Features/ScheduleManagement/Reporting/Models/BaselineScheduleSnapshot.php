<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\Models;

use Illuminate\Database\Eloquent\Model;

final class BaselineScheduleSnapshot extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'baseline_schedule_variance_snapshots';

    protected $guarded = [];

    protected $casts = [
        'as_of' => 'immutable_date',
        'generated_at' => 'immutable_datetime',
        'stale_at' => 'immutable_datetime',
        'watermarks' => 'array',
        'totals' => 'array',
        'source_refs' => 'array',
        'row_schema' => 'array',
    ];
}
