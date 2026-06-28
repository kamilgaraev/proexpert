<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Models;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EstimateGenerationTrainingFile extends Model
{
    public const ROLE_REFERENCE_ESTIMATE = 'reference_estimate';
    public const ROLE_PROJECT_DOCUMENT = 'project_document';
    public const ROLE_DRAWING = 'drawing';
    public const ROLE_SCAN = 'scan';
    public const ROLE_STATEMENT = 'statement';
    public const ROLE_OTHER = 'other';

    protected $fillable = [
        'training_dataset_id',
        'organization_id',
        'file_role',
        'storage_disk',
        'storage_path',
        'original_name',
        'mime_type',
        'file_size',
        'file_hash',
        'metadata',
    ];

    protected $casts = [
        'training_dataset_id' => 'integer',
        'organization_id' => 'integer',
        'file_size' => 'integer',
        'metadata' => 'array',
    ];

    public function trainingDataset(): BelongsTo
    {
        return $this->belongsTo(EstimateGenerationTrainingDataset::class, 'training_dataset_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function trainingExamples(): HasMany
    {
        return $this->hasMany(EstimateGenerationTrainingExample::class, 'estimate_file_id');
    }
}
