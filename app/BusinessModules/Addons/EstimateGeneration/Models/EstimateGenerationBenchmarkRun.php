<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EstimateGenerationBenchmarkRun extends Model
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'uuid', 'idempotency_key', 'organization_id', 'training_dataset_id', 'dataset_version',
        'pipeline_version', 'model_versions', 'normative_version', 'price_version', 'metrics',
        'case_results', 'case_results_storage_disk', 'case_results_storage_path', 'duration_ms',
        'cost_amount', 'currency', 'status', 'failure_code', 'started_at', 'completed_at',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'training_dataset_id' => 'integer',
        'dataset_version' => 'integer',
        'model_versions' => 'array',
        'metrics' => 'array',
        'case_results' => 'array',
        'duration_ms' => 'integer',
        'cost_amount' => 'decimal:8',
        'started_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
    ];

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(EstimateGenerationTrainingDataset::class, 'training_dataset_id');
    }
}
