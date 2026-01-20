<?php

namespace App\BusinessModules\Addons\AIEstimates\Models;

use App\BusinessModules\Addons\AIEstimates\Enums\GenerationStatus;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AIGenerationHistory extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'ai_generation_history';

    protected $fillable = [
        'organization_id',
        'project_id',
        'user_id',
        'input_description',
        'input_parameters',
        'uploaded_files',
        'ai_response',
        'matched_positions',
        'status',
        'tokens_used',
        'cost',
        'error_message',
        'generated_estimate_draft',
        'confidence_score',
        'ocr_results',
        'processing_time_ms',
    ];

    protected $casts = [
        'input_parameters' => 'array',
        'uploaded_files' => 'array',
        'ai_response' => 'array',
        'matched_positions' => 'array',
        'status' => GenerationStatus::class,
        'cost' => 'decimal:2',
        'confidence_score' => 'decimal:4',
        'generated_estimate_draft' => 'array',
        'ocr_results' => 'array',
        'processing_time_ms' => 'integer',
        'tokens_used' => 'integer',
    ];

    // Relationships

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

    public function feedback(): HasMany
    {
        return $this->hasMany(AIGenerationFeedback::class, 'generation_id');
    }

    // Scopes

    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeForProject($query, int $projectId)
    {
        return $query->where('project_id', $projectId);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', GenerationStatus::COMPLETED);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', GenerationStatus::FAILED);
    }

    public function scopeThisMonth($query)
    {
        return $query->whereBetween('created_at', [
            now()->startOfMonth(),
            now()->endOfMonth()
        ]);
    }

    // Helpers

    public function isCompleted(): bool
    {
        return $this->status === GenerationStatus::COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === GenerationStatus::FAILED;
    }

    public function canTransitionTo(GenerationStatus $newStatus): bool
    {
        return $this->status->canTransitionTo($newStatus);
    }

    public function hasFiles(): bool
    {
        return !empty($this->uploaded_files);
    }

    public function getAverageConfidence(): ?float
    {
        return $this->confidence_score;
    }

    public function getEstimatedCostRub(): float
    {
        return (float) $this->cost ?? 0.0;
    }

    public function getProcessingTimeSeconds(): ?float
    {
        if ($this->processing_time_ms === null) {
            return null;
        }
        return round($this->processing_time_ms / 1000, 2);
    }

    public function hasFeedback(): bool
    {
        return $this->feedback()->exists();
    }
}
