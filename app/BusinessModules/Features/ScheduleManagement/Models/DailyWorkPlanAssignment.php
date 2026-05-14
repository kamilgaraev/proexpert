<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Models;

use App\Models\ScheduleTask;
use App\Models\User;
use App\Models\ConstructionJournalEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DailyWorkPlanAssignment extends Model
{
    protected $fillable = [
        'organization_id',
        'project_id',
        'schedule_id',
        'daily_work_plan_id',
        'lookahead_plan_task_id',
        'schedule_task_id',
        'journal_entry_id',
        'assigned_user_id',
        'planned_quantity',
        'completed_quantity',
        'planned_work_hours',
        'actual_work_hours',
        'status',
        'failure_reason',
        'fact_comment',
        'metadata',
    ];

    protected $casts = [
        'planned_quantity' => 'decimal:4',
        'completed_quantity' => 'decimal:4',
        'planned_work_hours' => 'decimal:2',
        'actual_work_hours' => 'decimal:2',
        'metadata' => 'array',
    ];

    public function dailyWorkPlan(): BelongsTo
    {
        return $this->belongsTo(DailyWorkPlan::class);
    }

    public function lookaheadPlanTask(): BelongsTo
    {
        return $this->belongsTo(LookaheadPlanTask::class);
    }

    public function scheduleTask(): BelongsTo
    {
        return $this->belongsTo(ScheduleTask::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(ConstructionJournalEntry::class, 'journal_entry_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }
}
