<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Models;

use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class WorkConstraint extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'project_id',
        'schedule_id',
        'lookahead_plan_task_id',
        'schedule_task_id',
        'created_by_user_id',
        'resolved_by_user_id',
        'constraint_type',
        'title',
        'description',
        'severity',
        'status',
        'due_date',
        'resolved_at',
        'resolution_comment',
        'overridden_at',
        'overridden_by_user_id',
        'override_reason',
        'metadata',
    ];

    protected $casts = [
        'due_date' => 'date',
        'resolved_at' => 'datetime',
        'overridden_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function lookaheadPlanTask(): BelongsTo
    {
        return $this->belongsTo(LookaheadPlanTask::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ProjectSchedule::class, 'schedule_id');
    }

    public function scheduleTask(): BelongsTo
    {
        return $this->belongsTo(ScheduleTask::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
