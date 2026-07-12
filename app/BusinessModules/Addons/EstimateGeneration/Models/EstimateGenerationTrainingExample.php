<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstimateGenerationTrainingExample extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_INDEXED = 'indexed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'training_dataset_id',
        'organization_id',
        'dataset_version',
        'estimate_file_id',
        'learning_example_id',
        'source_row_hash',
        'row_number',
        'section_name',
        'section_path',
        'work_name',
        'work_unit',
        'work_quantity',
        'norm_code',
        'normative_name',
        'normative_unit',
        'status',
        'quality_score',
        'quality_flags',
        'work_intent',
        'source_refs',
        'raw_payload',
        'error_message',
        'accepted_at',
        'indexed_at',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'dataset_version' => 'integer',
        'training_dataset_id' => 'integer',
        'estimate_file_id' => 'integer',
        'learning_example_id' => 'integer',
        'row_number' => 'integer',
        'work_quantity' => 'decimal:6',
        'quality_score' => 'decimal:4',
        'quality_flags' => 'array',
        'work_intent' => 'array',
        'source_refs' => 'array',
        'raw_payload' => 'array',
        'accepted_at' => 'datetime',
        'indexed_at' => 'datetime',
        'reviewed_by' => 'integer',
        'reviewed_at' => 'immutable_datetime',
    ];

    public function trainingDataset(): BelongsTo
    {
        return $this->belongsTo(EstimateGenerationTrainingDataset::class, 'training_dataset_id');
    }

    public function estimateFile(): BelongsTo
    {
        return $this->belongsTo(EstimateGenerationTrainingFile::class, 'estimate_file_id');
    }

    public function learningExample(): BelongsTo
    {
        return $this->belongsTo(EstimateGenerationLearningExample::class, 'learning_example_id');
    }
}
