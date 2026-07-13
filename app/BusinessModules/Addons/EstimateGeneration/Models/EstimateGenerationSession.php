<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Models;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EstimateGenerationSession extends Model
{
    protected $table = 'estimate_generation_sessions';

    protected $fillable = [
        'organization_id',
        'project_id',
        'user_id',
        'status',
        'processing_stage',
        'processing_progress',
        'input_payload',
        'analysis_payload',
        'draft_payload',
        'problem_flags',
        'applied_estimate_id',
        'last_error',
        'failure_code',
        'state_version',
        'resume_status',
    ];

    protected $casts = [
        'input_payload' => 'array',
        'analysis_payload' => 'array',
        'draft_payload' => 'array',
        'problem_flags' => 'array',
        'processing_progress' => 'integer',
        'status' => EstimateGenerationStatus::class,
        'state_version' => 'integer',
        'applied_estimate_id' => 'integer',
        'applied_at' => 'immutable_datetime',
        'resume_status' => EstimateGenerationStatus::class,
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EstimateGenerationDocument::class, 'session_id');
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(EstimateGenerationFeedback::class, 'session_id');
    }

    public function packages(): HasMany
    {
        return $this->hasMany(EstimateGenerationPackage::class, 'session_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(EstimateGenerationAuditEvent::class, 'session_id');
    }

    public function checkpoints(): HasMany
    {
        return $this->hasMany(EstimateGenerationPipelineCheckpoint::class, 'session_id');
    }

    public function processingUnits(): HasMany
    {
        return $this->hasMany(EstimateGenerationProcessingUnit::class, 'session_id');
    }

    public function aiUsage(): HasMany
    {
        return $this->hasMany(EstimateGenerationAiUsage::class, 'session_id');
    }

    public function failures(): HasMany
    {
        return $this->hasMany(EstimateGenerationFailure::class, 'session_id');
    }

    public function drawingElements(): HasMany
    {
        return $this->hasMany(EstimateGenerationDrawingElement::class, 'session_id')
            ->orderBy('id');
    }

    public function quantityTakeoffs(): HasMany
    {
        return $this->hasMany(EstimateGenerationQuantityTakeoff::class, 'session_id')
            ->orderBy('id');
    }

    public function scopeInferences(): HasMany
    {
        return $this->hasMany(EstimateGenerationScopeInference::class, 'session_id')
            ->orderBy('id');
    }
}
