<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Enums\Schedule\DependencyTypeEnum;

class TaskDependency extends Model
{
    use HasFactory;

    protected $fillable = [
        'predecessor_task_id',
        'successor_task_id',
        'schedule_id',
        'organization_id',
        'created_by_user_id',
        'dependency_type',
        'lag_days',
        'lag_hours',
        'lag_type',
        'is_critical',
        'is_hard_constraint',
        'priority',
        'description',
        'constraint_reason',
        'is_active',
        'validation_status',
        'advanced_settings',
    ];

    protected $casts = [
        'lag_hours' => 'decimal:2',
        'is_critical' => 'boolean',
        'is_hard_constraint' => 'boolean',
        'is_active' => 'boolean',
        'dependency_type' => DependencyTypeEnum::class,
        'advanced_settings' => 'array',
        'priority' => 'integer',
    ];

    // === RELATIONSHIPS ===

    public function predecessorTask(): BelongsTo
    {
        return $this->belongsTo(ScheduleTask::class, 'predecessor_task_id');
    }

    public function successorTask(): BelongsTo
    {
        return $this->belongsTo(ScheduleTask::class, 'successor_task_id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ProjectSchedule::class, 'schedule_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    // === BUSINESS METHODS ===

    public function getTotalLag(): float
    {
        return match($this->lag_type) {
            'days' => $this->lag_days,
            'hours' => $this->lag_hours / 24, // Конвертируем в дни
            'percent' => $this->lag_days, // Процент от длительности предшествующей задачи
            default => $this->lag_days,
        };
    }

    public function calculateConstraintDate(): ?string
    {
        $predecessor = $this->predecessorTask;
        if (!$predecessor) {
            return null;
        }

        $constraintPoints = $this->dependency_type->constraintPoint();
        $predecessorDate = match($constraintPoints['predecessor']) {
            'start' => $predecessor->planned_start_date,
            'finish' => $predecessor->planned_end_date,
            default => $predecessor->planned_start_date,
        };

        if (!$predecessorDate) {
            return null;
        }

        $lagDays = $this->getTotalLag();
        return $predecessorDate->addDays($lagDays)->toDateString();
    }

    public function validateDependency(): string
    {
        // Проверка на циклические зависимости
        if ($this->createsCycle()) {
            return 'creates_cycle';
        }

        // Проверка логики дат
        if (!$this->hasValidDates()) {
            return 'invalid_dates';
        }

        // Проверка конфликтов ресурсов
        if ($this->hasResourceConflicts()) {
            return 'resource_conflict';
        }

        return 'valid';
    }

    protected function createsCycle(): bool
    {
        // Рекурсивная проверка на циклические зависимости
        return $this->checkCycleRecursive($this->successor_task_id, [$this->predecessor_task_id]);
    }

    protected function checkCycleRecursive(int $taskId, array $visited): bool
    {
        if (in_array($taskId, $visited)) {
            return true; // Найден цикл
        }

        $visited[] = $taskId;

        // Получаем все зависимости где текущая задача - предшественник
        $dependencies = self::where('predecessor_task_id', $taskId)
                           ->where('is_active', true)
                           ->get();

        foreach ($dependencies as $dependency) {
            if ($this->checkCycleRecursive($dependency->successor_task_id, $visited)) {
                return true;
            }
        }

        return false;
    }

    protected function hasValidDates(): bool
    {
        $predecessor = $this->predecessorTask;
        $successor = $this->successorTask;

        if (!$predecessor || !$successor) {
            return false;
        }

        $constraintDate = $this->calculateConstraintDate();
        if (!$constraintDate) {
            return false;
        }

        $constraintPoints = $this->dependency_type->constraintPoint();
        $successorDate = match($constraintPoints['successor']) {
            'start' => $successor->planned_start_date,
            'finish' => $successor->planned_end_date,
            default => $successor->planned_start_date,
        };

        return $successorDate >= $constraintDate;
    }

    protected function hasResourceConflicts(): bool
    {
        // Проверяем конфликты ресурсов между связанными задачами
        $predecessorResources = $this->predecessorTask->resources()->pluck('resource_id', 'resource_type');
        $successorResources = $this->successorTask->resources()->pluck('resource_id', 'resource_type');

        foreach ($predecessorResources as $type => $resourceId) {
            if (isset($successorResources[$type]) && $successorResources[$type] === $resourceId) {
                // Проверяем пересечение дат
                if ($this->hasDateOverlap()) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function hasDateOverlap(): bool
    {
        $predecessor = $this->predecessorTask;
        $successor = $this->successorTask;

        return $predecessor->planned_start_date <= $successor->planned_end_date &&
               $successor->planned_start_date <= $predecessor->planned_end_date;
    }

    // === SCOPES ===

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeCritical($query)
    {
        return $query->where('is_critical', true);
    }

    public function scopeByType($query, DependencyTypeEnum $type)
    {
        return $query->where('dependency_type', $type);
    }

    public function scopeForTask($query, int $taskId)
    {
        return $query->where(function ($q) use ($taskId) {
            $q->where('predecessor_task_id', $taskId)
              ->orWhere('successor_task_id', $taskId);
        });
    }

    public function scopeValid($query)
    {
        return $query->where('validation_status', 'valid');
    }
} 