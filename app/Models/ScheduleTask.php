<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\DB;
use App\Enums\Schedule\TaskStatusEnum;
use App\Enums\Schedule\TaskTypeEnum;
use App\Enums\Schedule\PriorityEnum;
use Carbon\Carbon;

class ScheduleTask extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'schedule_id',
        'organization_id',
        'parent_task_id',
        'work_type_id',
        'assigned_user_id',
        'created_by_user_id',
        'name',
        'description',
        'wbs_code',
        'task_type',
        'planned_start_date',
        'planned_end_date',
        'planned_duration_days',
        'planned_work_hours',
        'baseline_start_date',
        'baseline_end_date',
        'baseline_duration_days',
        'actual_start_date',
        'actual_end_date',
        'actual_duration_days',
        'actual_work_hours',
        'early_start_date',
        'early_finish_date',
        'late_start_date',
        'late_finish_date',
        'total_float_days',
        'free_float_days',
        'is_critical',
        'is_milestone_critical',
        'progress_percent',
        'status',
        'priority',
        'estimated_cost',
        'actual_cost',
        'earned_value',
        'required_resources',
        'constraint_type',
        'constraint_date',
        'custom_fields',
        'notes',
        'tags',
        'level',
        'sort_order',
    ];

    protected $casts = [
        'planned_start_date' => 'date',
        'planned_end_date' => 'date',
        'baseline_start_date' => 'date',
        'baseline_end_date' => 'date',
        'actual_start_date' => 'date',
        'actual_end_date' => 'date',
        'early_start_date' => 'date',
        'early_finish_date' => 'date',
        'late_start_date' => 'date',
        'late_finish_date' => 'date',
        'constraint_date' => 'date',
        'planned_work_hours' => 'decimal:2',
        'actual_work_hours' => 'decimal:2',
        'progress_percent' => 'decimal:2',
        'estimated_cost' => 'decimal:2',
        'actual_cost' => 'decimal:2',
        'earned_value' => 'decimal:2',
        'is_critical' => 'boolean',
        'is_milestone_critical' => 'boolean',
        'task_type' => TaskTypeEnum::class,
        'status' => TaskStatusEnum::class,
        'priority' => PriorityEnum::class,
        'required_resources' => 'array',
        'custom_fields' => 'array',
        'tags' => 'array',
    ];

    // === RELATIONSHIPS ===

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ProjectSchedule::class, 'schedule_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function parentTask(): BelongsTo
    {
        return $this->belongsTo(ScheduleTask::class, 'parent_task_id');
    }

    public function childTasks(): HasMany
    {
        return $this->hasMany(ScheduleTask::class, 'parent_task_id')
                    ->orderBy('sort_order');
    }

    public function workType(): BelongsTo
    {
        return $this->belongsTo(WorkType::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function predecessorDependencies(): HasMany
    {
        return $this->hasMany(TaskDependency::class, 'successor_task_id');
    }

    public function successorDependencies(): HasMany
    {
        return $this->hasMany(TaskDependency::class, 'predecessor_task_id');
    }

    public function resources(): HasMany
    {
        return $this->hasMany(TaskResource::class, 'task_id');
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(TaskMilestone::class, 'task_id');
    }

    public function completedWorks(): HasMany
    {
        return $this->hasMany(CompletedWork::class, 'schedule_task_id');
    }

    // === COMPUTED PROPERTIES ===

    public function getActualDurationDaysAttribute(): ?int
    {
        if (!$this->actual_start_date || !$this->actual_end_date) {
            return null;
        }
        return $this->actual_start_date->diffInDays($this->actual_end_date) + 1;
    }

    public function getScheduleVarianceAttribute(): ?int
    {
        if (!$this->baseline_start_date || !$this->baseline_end_date) {
            return null;
        }
        
        $baselineDuration = $this->baseline_start_date->diffInDays($this->baseline_end_date) + 1;
        return $this->planned_duration_days - $baselineDuration;
    }

    public function getCostVarianceAttribute(): ?float
    {
        if (!$this->estimated_cost || !$this->actual_cost) {
            return null;
        }
        return $this->actual_cost - $this->estimated_cost;
    }

    public function getEarnedValueAttribute(): float
    {
        if (!$this->estimated_cost) {
            return 0;
        }
        return $this->estimated_cost * ($this->progress_percent / 100);
    }

    public function getHealthStatusAttribute(): string
    {
        if ($this->status === TaskStatusEnum::COMPLETED) {
            return 'completed';
        }

        if ($this->status === TaskStatusEnum::CANCELLED) {
            return 'cancelled';
        }

        $scheduleVariance = $this->getScheduleVarianceAttribute();
        $costVariance = $this->getCostVarianceAttribute();
        
        if ($scheduleVariance > 3 || ($costVariance && $costVariance > $this->estimated_cost * 0.2)) {
            return 'critical';
        }
        
        if ($scheduleVariance > 1 || ($costVariance && $costVariance > $this->estimated_cost * 0.1)) {
            return 'warning';
        }
        
        return 'healthy';
    }

    public function getIsOverdueAttribute(): bool
    {
        if ($this->status === TaskStatusEnum::COMPLETED) {
            return false;
        }

        return $this->planned_end_date->isPast() && $this->progress_percent < 100;
    }

    public function getDaysUntilDeadlineAttribute(): int
    {
        return now()->diffInDays($this->planned_end_date, false);
    }

    // === BUSINESS METHODS ===

    public function start(?User $user = null): bool
    {
        if (!$this->status->canTransitionTo(TaskStatusEnum::IN_PROGRESS)) {
            return false;
        }

        $this->update([
            'status' => TaskStatusEnum::IN_PROGRESS,
            'actual_start_date' => now()->toDateString(),
        ]);

        // Пересчитываем критический путь если задача критическая
        if ($this->is_critical) {
            $this->schedule->update(['critical_path_calculated' => false]);
        }

        return true;
    }

    public function complete(?User $user = null): bool
    {
        if (!$this->status->canTransitionTo(TaskStatusEnum::COMPLETED)) {
            return false;
        }

        $this->update([
            'status' => TaskStatusEnum::COMPLETED,
            'progress_percent' => 100,
            'actual_end_date' => now()->toDateString(),
            'actual_duration_days' => $this->actual_start_date ? 
                $this->actual_start_date->diffInDays(now()) + 1 : 
                $this->planned_duration_days,
        ]);

        // Обновляем прогресс родительских задач
        $this->updateParentProgress();

        // Пересчитываем общий прогресс графика
        $this->schedule->recalculateProgress();

        return true;
    }

    public function updateProgress(float $percent): bool
    {
        if ($percent < 0 || $percent > 100) {
            return false;
        }

        $this->update(['progress_percent' => $percent]);

        // Автоматически меняем статус
        if ($percent == 0 && $this->status === TaskStatusEnum::IN_PROGRESS) {
            $this->update(['status' => TaskStatusEnum::NOT_STARTED]);
        } elseif ($percent > 0 && $percent < 100 && $this->status === TaskStatusEnum::NOT_STARTED) {
            $this->update(['status' => TaskStatusEnum::IN_PROGRESS]);
        } elseif ($percent == 100) {
            $this->complete();
            return true;
        }

        // Обновляем прогресс родительских задач
        $this->updateParentProgress();

        return true;
    }

    public function updateParentProgress(): void
    {
        if (!$this->parent_task_id) {
            return;
        }

        $parent = $this->parentTask;
        $siblings = $parent->childTasks;
        
        if ($siblings->isEmpty()) {
            return;
        }

        // Рассчитываем средний прогресс дочерних задач
        $totalWork = $siblings->sum('planned_work_hours');
        if ($totalWork == 0) {
            $averageProgress = $siblings->avg('progress_percent');
        } else {
            $weightedProgress = $siblings->sum(function ($task) {
                return $task->planned_work_hours * $task->progress_percent;
            });
            $averageProgress = $weightedProgress / $totalWork;
        }

        $parent->update(['progress_percent' => round($averageProgress, 2)]);
        
        // Рекурсивно обновляем родителей
        $parent->updateParentProgress();
    }

    public function generateWbsCode(): string
    {
        if ($this->parent_task_id) {
            $parentCode = $this->parentTask->wbs_code ?? $this->parentTask->generateWbsCode();
            $siblingNumber = $this->parentTask->childTasks()
                                 ->where('id', '<=', $this->id)
                                 ->count();
            return $parentCode . '.' . $siblingNumber;
        }

        // Для корневых задач
        $rootNumber = $this->schedule->rootTasks()
                           ->where('id', '<=', $this->id)
                           ->count();
        return (string) $rootNumber;
    }

    public function canHaveDependencies(): bool
    {
        return $this->task_type !== TaskTypeEnum::CONTAINER;
    }

    public function canHaveChildren(): bool
    {
        return $this->task_type->hasChildren();
    }

    public function canHaveResources(): bool
    {
        return $this->task_type->allowsResources();
    }

    // === SCOPES ===

    public function scopeRootTasks($query)
    {
        return $query->whereNull('parent_task_id')->orderBy('sort_order');
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', TaskStatusEnum::activeStatuses());
    }

    public function scopeCritical($query)
    {
        return $query->where('is_critical', true);
    }

    public function scopeOverdue($query)
    {
        return $query->where('planned_end_date', '<', now())
                    ->where('progress_percent', '<', 100)
                    ->whereNotIn('status', [TaskStatusEnum::COMPLETED->value, TaskStatusEnum::CANCELLED->value]);
    }

    public function scopeByType($query, TaskTypeEnum $type)
    {
        return $query->where('task_type', $type);
    }

    public function scopeAssignedTo($query, int $userId)
    {
        return $query->where('assigned_user_id', $userId);
    }

    public function scopeInProgress($query)
    {
        return $query->whereIn('status', TaskStatusEnum::workingStatuses());
    }
} 