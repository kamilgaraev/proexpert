<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Models;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class SafetyCorrectiveAction extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'project_id',
        'incident_id',
        'violation_id',
        'created_by_user_id',
        'assigned_to_user_id',
        'resolved_by_user_id',
        'verified_by_user_id',
        'action_number',
        'title',
        'description',
        'source_type',
        'severity',
        'status',
        'due_date',
        'resolution_comment',
        'resolved_at',
        'verification_comment',
        'verified_at',
        'metadata',
    ];

    protected $casts = [
        'due_date' => 'date',
        'resolved_at' => 'datetime',
        'verified_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(SafetyIncident::class, 'incident_id');
    }

    public function violation(): BelongsTo
    {
        return $this->belongsTo(SafetyViolation::class, 'violation_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
