<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Models;

use App\BusinessModules\Features\DesignManagement\Enums\DesignPackageStatusEnum;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class DesignPackage extends Model
{
    protected $fillable = [
        'organization_id',
        'project_id',
        'created_by',
        'updated_by',
        'title',
        'stage',
        'discipline',
        'status',
        'planned_issue_date',
        'metadata',
    ];

    protected $casts = [
        'status' => DesignPackageStatusEnum::class,
        'planned_issue_date' => 'date',
        'metadata' => 'array',
    ];

    protected $attributes = [
        'status' => 'draft',
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

    public function artifacts(): HasMany
    {
        return $this->hasMany(DesignArtifact::class, 'package_id')->orderByDesc('id');
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
