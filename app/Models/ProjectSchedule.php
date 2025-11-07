<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Enums\Schedule\ScheduleStatusEnum;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class ProjectSchedule extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'project_id',
        'organization_id',
        'created_by_user_id',
        'estimate_id',
        'sync_with_estimate',
        'last_synced_at',
        'sync_status',
        'name',
        'description',
        'planned_start_date',
        'planned_end_date',
        'baseline_start_date',
        'baseline_end_date',
        'baseline_saved_at',
        'baseline_saved_by_user_id',
        'actual_start_date',
        'actual_end_date',
        'status',
        'is_template',
        'template_name',
        'template_description',
        'calculation_settings',
        'display_settings',
        'critical_path_calculated',
        'critical_path_updated_at',
        'critical_path_duration_days',
        'total_estimated_cost',
        'total_actual_cost',
        'overall_progress_percent',
    ];

    protected $casts = [
        'planned_start_date' => 'date',
        'planned_end_date' => 'date',
        'baseline_start_date' => 'date',
        'baseline_end_date' => 'date',
        'baseline_saved_at' => 'datetime',
        'actual_start_date' => 'date',
        'actual_end_date' => 'date',
        'status' => ScheduleStatusEnum::class,
        'is_template' => 'boolean',
        'sync_with_estimate' => 'boolean',
        'last_synced_at' => 'datetime',
        'calculation_settings' => 'array',
        'display_settings' => 'array',
        'critical_path_calculated' => 'boolean',
        'critical_path_updated_at' => 'datetime',
        'total_estimated_cost' => 'decimal:2',
        'total_actual_cost' => 'decimal:2',
        'overall_progress_percent' => 'decimal:2',
    ];

    // === RELATIONSHIPS ===

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function baselineSavedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'baseline_saved_by_user_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ScheduleTask::class, 'schedule_id');
    }

    public function rootTasks(): HasMany
    {
        return $this->hasMany(ScheduleTask::class, 'schedule_id')
                    ->whereNull('parent_task_id')
                    ->orderBy('sort_order');
    }

    public function dependencies(): HasMany
    {
        return $this->hasMany(TaskDependency::class, 'schedule_id');
    }

    public function resources(): HasMany
    {
        return $this->hasMany(TaskResource::class, 'schedule_id');
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(TaskMilestone::class, 'schedule_id');
    }

    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    // === COMPUTED PROPERTIES ===

    public function getPlannedDurationDaysAttribute(): int
    {
        return $this->planned_start_date->diffInDays($this->planned_end_date) + 1;
    }

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
        $currentDuration = $this->getPlannedDurationDaysAttribute();
        
        return $currentDuration - $baselineDuration;
    }

    public function getCostVarianceAttribute(): ?float
    {
        if (!$this->total_estimated_cost || !$this->total_actual_cost) {
            return null;
        }
        
        return $this->total_actual_cost - $this->total_estimated_cost;
    }

    public function getHealthStatusAttribute(): string
    {
        if ($this->status === ScheduleStatusEnum::COMPLETED) {
            return 'completed';
        }

        if ($this->status === ScheduleStatusEnum::CANCELLED) {
            return 'cancelled';
        }

        $scheduleVariance = $this->getScheduleVarianceAttribute();
        $costVariance = $this->getCostVarianceAttribute();
        
        if ($scheduleVariance > 7 || ($costVariance && $costVariance > $this->total_estimated_cost * 0.1)) {
            return 'critical';
        }
        
        if ($scheduleVariance > 3 || ($costVariance && $costVariance > $this->total_estimated_cost * 0.05)) {
            return 'warning';
        }
        
        return 'healthy';
    }

    // === BUSINESS METHODS ===

    public function saveBaseline(?User $user = null): bool
    {
        if ($this->status !== ScheduleStatusEnum::ACTIVE) {
            return false;
        }

        $this->update([
            'baseline_start_date' => $this->planned_start_date,
            'baseline_end_date' => $this->planned_end_date,
            'baseline_saved_at' => now(),
            'baseline_saved_by_user_id' => $user?->id ?? Auth::id(),
        ]);

        // Сохраняем базовые даты для всех задач
        $this->tasks()->update([
            'baseline_start_date' => DB::raw('planned_start_date'),
            'baseline_end_date' => DB::raw('planned_end_date'),
            'baseline_duration_days' => DB::raw('planned_duration_days'),
        ]);

        return true;
    }

    public function clearBaseline(): bool
    {
        $this->update([
            'baseline_start_date' => null,
            'baseline_end_date' => null,
            'baseline_saved_at' => null,
            'baseline_saved_by_user_id' => null,
        ]);

        // Очищаем базовые даты для всех задач
        $this->tasks()->update([
            'baseline_start_date' => null,
            'baseline_end_date' => null,
            'baseline_duration_days' => null,
        ]);

        return true;
    }

    public function recalculateProgress(): float
    {
        $tasks = $this->tasks()->where('task_type', 'task')->get();
        
        if ($tasks->isEmpty()) {
            return 0;
        }

        $totalWork = $tasks->sum('planned_work_hours');
        if ($totalWork == 0) {
            return 0;
        }

        $completedWork = $tasks->sum(function ($task) {
            return $task->planned_work_hours * ($task->progress_percent / 100);
        });

        $progress = ($completedWork / $totalWork) * 100;
        
        $this->update(['overall_progress_percent' => round($progress, 2)]);
        
        return $progress;
    }

    public function markCriticalPathCalculated(int $durationDays): void
    {
        $this->update([
            'critical_path_calculated' => true,
            'critical_path_updated_at' => now(),
            'critical_path_duration_days' => $durationDays,
        ]);
    }

    public function needsCriticalPathRecalculation(): bool
    {
        if (!$this->critical_path_calculated) {
            return true;
        }

        if (!$this->critical_path_updated_at) {
            return true;
        }

        // Пересчитываем если изменения были позже последнего расчета
        $lastTaskUpdate = $this->tasks()->max('updated_at');
        $lastDependencyUpdate = $this->dependencies()->max('updated_at');
        
        $lastUpdate = max($lastTaskUpdate, $lastDependencyUpdate);
        
        return $lastUpdate > $this->critical_path_updated_at;
    }

    public function needsSync(): bool
    {
        if (!$this->estimate_id || !$this->sync_with_estimate) {
            return false;
        }

        if ($this->sync_status === 'out_of_sync' || $this->sync_status === 'conflict') {
            return true;
        }

        if (!$this->last_synced_at) {
            return true;
        }

        // Проверяем, обновлялась ли смета после последней синхронизации
        if ($this->estimate && $this->estimate->updated_at > $this->last_synced_at) {
            return true;
        }

        return false;
    }

    // === SCOPES ===

    public function scopeActive($query)
    {
        return $query->whereIn('status', ScheduleStatusEnum::activeStatuses());
    }

    public function scopeTemplate($query)
    {
        return $query->where('is_template', true);
    }

    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeWithCriticalPath($query)
    {
        return $query->where('critical_path_calculated', true);
    }
} 