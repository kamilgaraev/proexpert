<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class EstimateOperation extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $casts = [
        'parameters' => 'array', 'source_snapshot' => 'array', 'resolutions' => 'array',
        'summary' => 'array', 'result_ids' => 'array', 'generation' => 'integer',
    ];
}
