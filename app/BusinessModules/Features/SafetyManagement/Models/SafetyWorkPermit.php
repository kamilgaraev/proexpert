<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Models;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $project_id
 * @property int|null $created_by_user_id
 * @property int|null $responsible_user_id
 * @property int|null $approved_by_user_id
 * @property int|null $rejected_by_user_id
 * @property int|null $suspended_by_user_id
 * @property int|null $closed_by_user_id
 * @property string $permit_number
 * @property string $title
 * @property string $permit_type
 * @property string|null $location_name
 * @property string $risk_level
 * @property Carbon $valid_from
 * @property Carbon $valid_until
 * @property array<int, string>|null $required_controls
 * @property string $status
 * @property Carbon|null $submitted_at
 * @property Carbon|null $approved_at
 * @property Carbon|null $activated_at
 * @property Carbon|null $rejected_at
 * @property Carbon|null $suspended_at
 * @property Carbon|null $closed_at
 * @property string|null $approval_comment
 * @property string|null $rejection_reason
 * @property string|null $suspension_reason
 * @property string|null $close_comment
 * @property array<string, mixed>|null $metadata
 */
final class SafetyWorkPermit extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'project_id',
        'created_by_user_id',
        'responsible_user_id',
        'approved_by_user_id',
        'rejected_by_user_id',
        'suspended_by_user_id',
        'closed_by_user_id',
        'permit_number',
        'title',
        'permit_type',
        'location_name',
        'risk_level',
        'valid_from',
        'valid_until',
        'required_controls',
        'status',
        'submitted_at',
        'approved_at',
        'activated_at',
        'rejected_at',
        'suspended_at',
        'closed_at',
        'approval_comment',
        'rejection_reason',
        'suspension_reason',
        'close_comment',
        'metadata',
    ];

    protected $casts = [
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
        'required_controls' => 'array',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'activated_at' => 'datetime',
        'rejected_at' => 'datetime',
        'suspended_at' => 'datetime',
        'closed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(SafetyWorkPermitParticipant::class, 'permit_id');
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
