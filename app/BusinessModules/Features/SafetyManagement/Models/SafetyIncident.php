<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Models;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $project_id
 * @property int|null $reported_by_user_id
 * @property int|null $assigned_to_user_id
 * @property int|null $triaged_by_user_id
 * @property int|null $cancelled_by_user_id
 * @property int|null $closed_by_user_id
 * @property string $incident_number
 * @property string $title
 * @property string $incident_type
 * @property string $severity
 * @property string $status
 * @property Carbon $occurred_at
 * @property string|null $location_name
 * @property string|null $description
 * @property string|null $immediate_actions
 * @property string|null $root_cause
 * @property string|null $corrective_actions
 * @property string|null $triage_comment
 * @property string|null $cancellation_reason
 * @property Carbon|null $triaged_at
 * @property Carbon|null $investigation_started_at
 * @property Carbon|null $corrective_actions_started_at
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $closed_at
 * @property array<string, mixed>|null $metadata
 */
final class SafetyIncident extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'project_id',
        'reported_by_user_id',
        'assigned_to_user_id',
        'triaged_by_user_id',
        'cancelled_by_user_id',
        'closed_by_user_id',
        'incident_number',
        'title',
        'incident_type',
        'severity',
        'status',
        'occurred_at',
        'location_name',
        'description',
        'immediate_actions',
        'root_cause',
        'corrective_actions',
        'triage_comment',
        'cancellation_reason',
        'triaged_at',
        'investigation_started_at',
        'corrective_actions_started_at',
        'cancelled_at',
        'closed_at',
        'metadata',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'triaged_at' => 'datetime',
        'investigation_started_at' => 'datetime',
        'corrective_actions_started_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'closed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
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
