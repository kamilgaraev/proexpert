<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\MachineryOperations\Models;

use App\BusinessModules\Core\AssetManagement\Models\OrganizationAsset;
use App\Models\Project;
use App\Models\ScheduleTask;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class AssetRequest extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'project_id', 'schedule_task_id', 'requested_by_user_id',
        'approved_by_user_id', 'organization_asset_id', 'status', 'priority',
        'planned_start_at', 'planned_end_at', 'required_profile', 'purpose', 'decision_comment',
    ];

    protected $casts = [
        'planned_start_at' => 'datetime',
        'planned_end_at' => 'datetime',
        'required_profile' => 'array',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function scheduleTask(): BelongsTo
    {
        return $this->belongsTo(ScheduleTask::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function organizationAsset(): BelongsTo
    {
        return $this->belongsTo(OrganizationAsset::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(AssetRequestEvent::class);
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
