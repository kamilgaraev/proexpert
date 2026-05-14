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
 * @property int|null $created_by_user_id
 * @property int|null $assigned_to_user_id
 * @property int|null $resolved_by_user_id
 * @property string $violation_number
 * @property string $title
 * @property string $severity
 * @property string $status
 * @property string|null $location_name
 * @property string|null $description
 * @property string|null $corrective_action
 * @property Carbon|null $due_date
 * @property Carbon|null $resolved_at
 * @property string|null $resolution_comment
 * @property array<string, mixed>|null $metadata
 */
final class SafetyViolation extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'project_id',
        'created_by_user_id',
        'assigned_to_user_id',
        'resolved_by_user_id',
        'violation_number',
        'title',
        'severity',
        'status',
        'location_name',
        'description',
        'corrective_action',
        'due_date',
        'resolved_at',
        'resolution_comment',
        'metadata',
    ];

    protected $casts = [
        'due_date' => 'date',
        'resolved_at' => 'datetime',
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
