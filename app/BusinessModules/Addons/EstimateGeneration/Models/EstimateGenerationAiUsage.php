<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Models;

use Illuminate\Database\Eloquent\Model;

final class EstimateGenerationAiUsage extends Model
{
    public $timestamps = false;

    protected $table = 'estimate_generation_ai_usage';

    protected $guarded = [];

    protected $casts = [
        'price_snapshot' => 'array',
        'created_at' => 'immutable_datetime',
    ];
}
