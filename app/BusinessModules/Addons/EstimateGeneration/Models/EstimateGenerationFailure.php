<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Models;

use Illuminate\Database\Eloquent\Model;

final class EstimateGenerationFailure extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $table = 'estimate_generation_failures';

    protected $guarded = [];

    protected $casts = [
        'safe_context' => 'array',
        'first_seen_at' => 'immutable_datetime',
        'last_seen_at' => 'immutable_datetime',
        'resolved_at' => 'immutable_datetime',
        'occurrence_count' => 'integer',
    ];
}
