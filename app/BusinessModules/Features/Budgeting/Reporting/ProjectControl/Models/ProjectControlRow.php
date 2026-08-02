<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Models;

use Illuminate\Database\Eloquent\Model;

final class ProjectControlRow extends Model
{
    public $timestamps = false;

    protected $table = 'project_control_rows';

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'source_refs' => 'array',
    ];
}
