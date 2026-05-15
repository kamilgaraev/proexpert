<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\MachineryOperations\Models;

use App\Models\Project;
use App\Models\ScheduleTask;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class MachineryAssignment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'asset_id',
        'project_id',
        'schedule_task_id',
        'requested_by_user_id',
        'approved_by_user_id',
        'status',
        'planned_start_at',
        'planned_end_at',
        'actual_start_at',
        'actual_end_at',
        'planned_hours',
        'comment',
    ];

    protected $casts = [
        'planned_start_at' => 'datetime',
        'planned_end_at' => 'datetime',
        'actual_start_at' => 'datetime',
        'actual_end_at' => 'datetime',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(MachineryAsset::class, 'asset_id');
    }

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

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
