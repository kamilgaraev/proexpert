<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Models;

use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class LookaheadPlanTask extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'project_id',
        'schedule_id',
        'lookahead_plan_id',
        'schedule_task_id',
        'planned_start_date',
        'planned_end_date',
        'planned_quantity',
        'planned_work_hours',
        'readiness_status',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'planned_start_date' => 'date',
        'planned_end_date' => 'date',
        'planned_quantity' => 'decimal:4',
        'planned_work_hours' => 'decimal:2',
        'metadata' => 'array',
    ];

    public function lookaheadPlan(): BelongsTo
    {
        return $this->belongsTo(LookaheadPlan::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ProjectSchedule::class, 'schedule_id');
    }

    public function scheduleTask(): BelongsTo
    {
        return $this->belongsTo(ScheduleTask::class);
    }

    public function constraints(): HasMany
    {
        return $this->hasMany(WorkConstraint::class);
    }
}
