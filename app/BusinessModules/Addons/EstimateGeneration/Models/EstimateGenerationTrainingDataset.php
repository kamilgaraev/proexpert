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
    public const TYPE_DEVELOPMENT = 'development';

    public const TYPE_REGRESSION = 'regression';

    public const TYPE_ACCEPTANCE = 'acceptance';

    public const TYPES = [self::TYPE_DEVELOPMENT, self::TYPE_REGRESSION, self::TYPE_ACCEPTANCE];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_REVIEW_REQUIRED = 'review_required';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_PROCESSING, self::STATUS_REVIEW_REQUIRED, self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_ARCHIVED];

    protected $fillable = [
        'uuid',
        'dataset_key',
        'version',
        'dataset_type',
        'scope',
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
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'version' => 'integer',
        'project_id' => 'integer',
        'created_by_system_admin_id' => 'integer',
        'source_quality_score' => 'decimal:4',
        'stats' => 'array',
        'processing_payload' => 'array',
        'queued_at' => 'datetime',
        'processed_at' => 'datetime',
        'accepted_at' => 'datetime',
        'approved_at' => 'immutable_datetime',
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
