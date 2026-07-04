<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Models;

use App\Models\Organization;
use App\Models\Project;
use App\Models\WorkType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class SafetyRequirementMatrix extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'project_id',
        'work_type_id',
        'position_name',
        'work_category',
        'risk_level',
        'requirements',
        'is_active',
        'effective_from',
        'effective_until',
    ];

    protected $casts = [
        'requirements' => 'array',
        'is_active' => 'boolean',
        'effective_from' => 'date',
        'effective_until' => 'date',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function workType(): BelongsTo
    {
        return $this->belongsTo(WorkType::class);
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
