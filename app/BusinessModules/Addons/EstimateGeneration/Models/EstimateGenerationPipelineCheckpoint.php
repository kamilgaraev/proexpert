<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Models;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CheckpointStatus;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EstimateGenerationPipelineCheckpoint extends Model
{
    protected $table = 'estimate_generation_pipeline_checkpoints';

    protected $fillable = [
        'session_id',
        'organization_id',
        'project_id',
        'generation_attempt_id',
        'base_input_version',
        'stage',
        'input_version',
        'dependency_versions',
        'output_version',
        'output_payload',
        'artifact_bytes',
        'status',
        'metrics',
        'warnings',
        'attempt_count',
        'claim_token',
        'lease_expires_at',
        'started_at',
        'completed_at',
        'failed_at',
        'invalidated_at',
        'invalidation_reason',
        'last_error_code',
        'last_error_message',
        'last_error_fingerprint',
    ];

    protected $casts = [
        'stage' => ProcessingStage::class,
        'status' => CheckpointStatus::class,
        'metrics' => 'array',
        'warnings' => 'array',
        'dependency_versions' => 'array',
        'output_payload' => 'array',
        'artifact_bytes' => 'integer',
        'attempt_count' => 'integer',
        'lease_expires_at' => 'immutable_datetime',
        'started_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
        'failed_at' => 'immutable_datetime',
        'invalidated_at' => 'immutable_datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(EstimateGenerationSession::class, 'session_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }
}
