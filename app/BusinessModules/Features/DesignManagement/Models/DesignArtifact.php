<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Models;

use App\BusinessModules\Features\DesignManagement\Enums\DesignArtifactTypeEnum;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class DesignArtifact extends Model
{
    protected $fillable = [
        'organization_id',
        'project_id',
        'package_id',
        'created_by',
        'updated_by',
        'artifact_type',
        'title',
        'discipline',
        'stage',
        'status',
        'metadata',
    ];

    protected $casts = [
        'artifact_type' => DesignArtifactTypeEnum::class,
        'metadata' => 'array',
    ];

    protected $attributes = [
        'artifact_type' => 'model',
        'status' => 'active',
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

    public function package(): BelongsTo
    {
        return $this->belongsTo(DesignPackage::class, 'package_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DesignArtifactVersion::class, 'artifact_id')->orderByDesc('id');
    }

    public function currentVersion(): HasOne
    {
        return $this->hasOne(DesignArtifactVersion::class, 'artifact_id')->where('is_current', true);
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
