<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class OneCExchangeRun extends Model
{
    protected $fillable = [
        'organization_id',
        'created_by',
        'direction',
        'scope',
        'status',
        'total_count',
        'created_count',
        'updated_count',
        'skipped_count',
        'error_count',
        'errors',
        'summary',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'errors' => 'array',
        'summary' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
