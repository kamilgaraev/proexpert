<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Models;

use App\Models\Organization;
use App\Models\Project;
use App\Models\SystemAdmin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EstimateGenerationTrainingDataset extends Model
{
    public const STATUS_UPLOADED = 'uploaded';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'uuid',
        'organization_id',
        'project_id',
        'created_by_system_admin_id',
        'title',
        'source_system',
        'status',
        'quality_status',
        'source_quality_score',
        'region_name',
        'period_name',
        'notes',
        'stats',
        'processing_payload',
        'error_message',
        'queued_at',
        'processed_at',
        'accepted_at',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'project_id' => 'integer',
        'created_by_system_admin_id' => 'integer',
        'source_quality_score' => 'decimal:4',
        'stats' => 'array',
        'processing_payload' => 'array',
        'queued_at' => 'datetime',
        'processed_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function createdBySystemAdmin(): BelongsTo
    {
        return $this->belongsTo(SystemAdmin::class, 'created_by_system_admin_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(EstimateGenerationTrainingFile::class, 'training_dataset_id');
    }

    public function examples(): HasMany
    {
        return $this->hasMany(EstimateGenerationTrainingExample::class, 'training_dataset_id');
    }

    public function referenceEstimateFile(): ?EstimateGenerationTrainingFile
    {
        return $this->files->firstWhere('file_role', EstimateGenerationTrainingFile::ROLE_REFERENCE_ESTIMATE);
    }
}
