<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Models;

use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class SafetyEmployeeRequirement extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'employee_id',
        'user_id',
        'project_id',
        'work_type_id',
        'work_category',
        'requirement_code',
        'requirement_type',
        'source_type',
        'source_id',
        'valid_from',
        'valid_until',
        'status',
        'metadata',
    ];

    protected $casts = [
        'valid_from' => 'date',
        'valid_until' => 'date',
        'metadata' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(WorkforceEmployee::class, 'employee_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
