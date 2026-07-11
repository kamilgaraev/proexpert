<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Models;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CheckpointStatus;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EstimateGenerationPipelineCheckpoint extends Model
{
    protected $table = 'estimate_generation_pipeline_checkpoints';

    protected $fillable = [
        'session_id',
        'stage',
        'input_version',
        'output_version',
        'output_payload',
        'status',
        'metrics',
        'warnings',
        'attempt_count',
        'claim_token',
        'lease_expires_at',
        'started_at',
        'completed_at',
        'failed_at',
        'last_error_code',
        'last_error_message',
        'last_error_fingerprint',
    ];

    protected $casts = [
        'stage' => ProcessingStage::class,
        'status' => CheckpointStatus::class,
        'metrics' => 'array',
        'warnings' => 'array',
        'output_payload' => 'array',
        'attempt_count' => 'integer',
        'lease_expires_at' => 'immutable_datetime',
        'started_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
        'failed_at' => 'immutable_datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(EstimateGenerationSession::class, 'session_id');
    }
}
