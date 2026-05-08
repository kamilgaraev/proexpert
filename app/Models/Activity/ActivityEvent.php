<?php

declare(strict_types=1);

namespace App\Models\Activity;

use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityEvent extends Model
{
    protected $fillable = [
        'organization_id',
        'actor_user_id',
        'actor_type',
        'actor_name',
        'actor_email',
        'interface',
        'module',
        'event_type',
        'action',
        'result',
        'severity',
        'subject_type',
        'subject_id',
        'subject_label',
        'project_id',
        'target_user_id',
        'title',
        'description',
        'changes',
        'context',
        'ip_address',
        'user_agent',
        'correlation_id',
        'occurred_at',
    ];

    protected $casts = [
        'changes' => 'array',
        'context' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
