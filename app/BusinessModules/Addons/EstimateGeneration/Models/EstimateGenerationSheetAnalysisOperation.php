<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Models;

use Illuminate\Database\Eloquent\Model;

final class EstimateGenerationSheetAnalysisOperation extends Model
{
    protected $table = 'estimate_generation_sheet_analysis_operations';

    protected $primaryKey = 'operation_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'analysis_payload' => 'array',
        'initial_routing' => 'array',
        'final_routing' => 'array',
        'lease_expires_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
        'audit_recorded_at' => 'immutable_datetime',
    ];
}
