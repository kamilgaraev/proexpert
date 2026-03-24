<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Models;

use App\Models\Estimate;
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
    ];

    protected $casts = [
        'input_payload' => 'array',
        'analysis_payload' => 'array',
        'draft_payload' => 'array',
        'problem_flags' => 'array',
        'processing_progress' => 'integer',
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

    public function appliedEstimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class, 'applied_estimate_id');
    }
}
