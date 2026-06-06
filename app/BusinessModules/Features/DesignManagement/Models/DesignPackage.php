<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Models;

use App\BusinessModules\Features\DesignManagement\Enums\DesignPackageStatusEnum;
use App\BusinessModules\Features\DesignManagement\Enums\DesignObjectTypeEnum;
use App\BusinessModules\Features\DesignManagement\Enums\DesignProjectStageEnum;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class DesignPackage extends Model
{
    protected $fillable = [
        'organization_id',
        'project_id',
        'created_by',
        'updated_by',
        'title',
        'stage',
        'project_stage',
        'object_type',
        'normative_profile_code',
        'discipline',
        'status',
        'planned_issue_date',
        'issued_at',
        'issued_by',
        'metadata',
    ];

    protected $casts = [
        'project_stage' => DesignProjectStageEnum::class,
        'object_type' => DesignObjectTypeEnum::class,
        'status' => DesignPackageStatusEnum::class,
        'planned_issue_date' => 'date',
        'issued_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected $attributes = [
        'status' => 'draft',
        'project_stage' => 'rd',
        'object_type' => 'non_linear_non_production',
        'normative_profile_code' => 'rf_rd_gost_21_101_2026',
        'metadata' => '{}',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(DesignArtifact::class, 'package_id')->orderByDesc('id');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(DesignPackageSection::class, 'package_id')->orderBy('sort_order')->orderBy('code');
    }

    public function reviewRounds(): HasMany
    {
        return $this->hasMany(DesignReviewRound::class, 'package_id')->orderByDesc('round_number');
    }

    public function reviewComments(): HasMany
    {
        return $this->hasMany(DesignReviewComment::class, 'package_id')->orderByDesc('id');
    }

    public function workflowEvents(): HasMany
    {
        return $this->hasMany(DesignWorkflowEvent::class, 'package_id')->orderBy('id');
    }

    public function completenessChecks(): HasMany
    {
        return $this->hasMany(DesignCompletenessCheck::class, 'package_id')->orderByDesc('checked_at');
    }

    public function latestCompletenessCheck(): HasOne
    {
        return $this->hasOne(DesignCompletenessCheck::class, 'package_id')->latestOfMany('checked_at');
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeForProject(Builder $query, int $projectId): Builder
    {
        return $query->where('project_id', $projectId);
    }
}
