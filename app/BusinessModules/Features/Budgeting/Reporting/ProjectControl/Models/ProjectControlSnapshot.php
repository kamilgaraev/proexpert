<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Models;

use Illuminate\Database\Eloquent\Model;

final class ProjectControlSnapshot extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'project_control_snapshots';

    protected $guarded = [];

    protected $casts = [
        'status_date' => 'immutable_date',
        'generated_at' => 'immutable_datetime',
        'stale_at' => 'immutable_datetime',
        'watermarks' => 'array',
        'totals' => 'array',
        'source_refs' => 'array',
        'row_schema' => 'array',
    ];
}
