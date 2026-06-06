<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Models;

use App\BusinessModules\Features\DesignManagement\Enums\DesignCompletenessStatusEnum;
use App\BusinessModules\Features\DesignManagement\Enums\DesignObjectTypeEnum;
use App\BusinessModules\Features\DesignManagement\Enums\DesignProjectStageEnum;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DesignCompletenessCheck extends Model
{
    protected $fillable = [
        'organization_id',
        'project_id',
        'package_id',
        'created_by',
        'status',
        'profile_code',
        'project_stage',
        'object_type',
        'checked_at',
        'blocking_count',
        'warning_count',
        'summary',
        'results',
        'metadata',
    ];

    protected $casts = [
        'status' => DesignCompletenessStatusEnum::class,
        'project_stage' => DesignProjectStageEnum::class,
        'object_type' => DesignObjectTypeEnum::class,
        'checked_at' => 'datetime',
        'blocking_count' => 'integer',
        'warning_count' => 'integer',
        'summary' => 'array',
        'results' => 'array',
        'metadata' => 'array',
    ];

    protected $attributes = [
        'status' => 'blocked',
        'blocking_count' => 0,
        'warning_count' => 0,
        'summary' => '{}',
        'results' => '[]',
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

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
