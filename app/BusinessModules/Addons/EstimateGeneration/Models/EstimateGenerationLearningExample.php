<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Models;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateDatasetVersion;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateNorm;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstimateGenerationLearningExample extends Model
{
    protected $table = 'estimate_generation_learning_examples';

    protected $fillable = [
        'organization_id',
        'project_id',
        'source_type',
        'source_entity_type',
        'source_entity_id',
        'estimate_id',
        'estimate_item_id',
        'generation_session_id',
        'generation_package_item_id',
        'work_name',
        'work_unit',
        'work_quantity',
        'work_intent',
        'normative_dataset_version_id',
        'estimate_norm_id',
        'norm_code',
        'normative_name',
        'normative_unit',
        'decision_status',
        'confidence',
        'is_positive',
        'source_quality_score',
        'context_payload',
        'source_refs',
        'quality_flags',
        'accepted_at',
        'indexed_at',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'project_id' => 'integer',
        'source_entity_id' => 'integer',
        'estimate_id' => 'integer',
        'estimate_item_id' => 'integer',
        'generation_session_id' => 'integer',
        'generation_package_item_id' => 'integer',
        'work_quantity' => 'decimal:6',
        'work_intent' => 'array',
        'normative_dataset_version_id' => 'integer',
        'estimate_norm_id' => 'integer',
        'confidence' => 'decimal:4',
        'is_positive' => 'boolean',
        'source_quality_score' => 'decimal:4',
        'context_payload' => 'array',
        'source_refs' => 'array',
        'quality_flags' => 'array',
        'accepted_at' => 'datetime',
        'indexed_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function generationSession(): BelongsTo
    {
        return $this->belongsTo(EstimateGenerationSession::class, 'generation_session_id');
    }

    public function generationPackageItem(): BelongsTo
    {
        return $this->belongsTo(EstimateGenerationPackageItem::class, 'generation_package_item_id');
    }

    public function datasetVersion(): BelongsTo
    {
        return $this->belongsTo(EstimateDatasetVersion::class, 'normative_dataset_version_id');
    }

    public function estimateNorm(): BelongsTo
    {
        return $this->belongsTo(EstimateNorm::class, 'estimate_norm_id');
    }
}
