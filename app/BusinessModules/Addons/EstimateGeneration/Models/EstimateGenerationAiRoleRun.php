<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Models;

use Illuminate\Database\Eloquent\Model;

final class EstimateGenerationAiRoleRun extends Model
{
    protected $table = 'estimate_generation_ai_role_runs';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'result_payload' => 'array',
            'lease_expires_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }
}
