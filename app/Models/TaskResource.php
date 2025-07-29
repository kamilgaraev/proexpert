<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TaskResource extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'task_id',
        'schedule_id',
        'organization_id',
        'assigned_by_user_id',
        'resource_type',
        'resource_id',
        'resource_model',
        'user_id',
        'material_id',
        'equipment_name',
        'external_resource_name',
        'allocated_units',
        'allocated_hours',
        'actual_hours',
        'allocation_percent',
        'assignment_start_date',
        'assignment_end_date',
        'cost_per_hour',
        'cost_per_unit',
        'total_planned_cost',
        'total_actual_cost',
        'assignment_status',
        'priority',
        'role',
        'requirements',
        'working_calendar',
        'daily_working_hours',
        'has_conflicts',
        'conflict_details',
        'notes',
        'allocation_details',
    ];

    protected $casts = [
        'assignment_start_date' => 'date',
        'assignment_end_date' => 'date',
        'allocated_units' => 'decimal:2',
        'allocated_hours' => 'decimal:2',
        'actual_hours' => 'decimal:2',
        'allocation_percent' => 'decimal:2',
        'cost_per_hour' => 'decimal:2',
        'cost_per_unit' => 'decimal:2',
        'total_planned_cost' => 'decimal:2',
        'total_actual_cost' => 'decimal:2',
        'daily_working_hours' => 'decimal:2',
        'has_conflicts' => 'boolean',
        'requirements' => 'array',
        'working_calendar' => 'array',
        'conflict_details' => 'array',
        'allocation_details' => 'array',
    ];

    // === RELATIONSHIPS ===

    public function task(): BelongsTo
    {
        return $this->belongsTo(ScheduleTask::class, 'task_id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ProjectSchedule::class, 'schedule_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    // Полиморфная связь для ресурса
    public function resource(): MorphTo
    {
        return $this->morphTo('resource', 'resource_model', 'resource_id');
    }

    // === COMPUTED PROPERTIES ===

    public function getResourceNameAttribute(): string
    {
        return match($this->resource_type) {
            'user' => $this->user?->name ?? 'Пользователь не найден',
            'material' => $this->material?->name ?? 'Материал не найден',
            'equipment' => $this->equipment_name ?? 'Оборудование',
            'external_resource' => $this->external_resource_name ?? 'Внешний ресурс',
            default => 'Неизвестный ресурс',
        };
    }

    public function getTotalCostAttribute(): float
    {
        if ($this->cost_per_hour && $this->allocated_hours) {
            return $this->cost_per_hour * $this->allocated_hours;
        }

        if ($this->cost_per_unit && $this->allocated_units) {
            return $this->cost_per_unit * $this->allocated_units;
        }

        return $this->total_planned_cost ?? 0;
    }

    public function getUsagePercentAttribute(): float
    {
        if (!$this->allocated_hours || $this->allocated_hours == 0) {
            return 0;
        }

        return ($this->actual_hours / $this->allocated_hours) * 100;
    }

    public function getCostVarianceAttribute(): float
    {
        return $this->total_actual_cost - $this->getTotalCostAttribute();
    }

    public function getAssignmentDurationDaysAttribute(): int
    {
        if (!$this->assignment_start_date || !$this->assignment_end_date) {
            return 0;
        }

        return $this->assignment_start_date->diffInDays($this->assignment_end_date) + 1;
    }

    // === BUSINESS METHODS ===

    public function calculatePlannedCost(): float
    {
        $cost = 0;

        if ($this->cost_per_hour && $this->allocated_hours) {
            $cost = $this->cost_per_hour * $this->allocated_hours;
        } elseif ($this->cost_per_unit && $this->allocated_units) {
            $cost = $this->cost_per_unit * $this->allocated_units;
        }

        $this->update(['total_planned_cost' => $cost]);
        
        return $cost;
    }

    public function updateActualCost(float $actualHours = null): float
    {
        if ($actualHours !== null) {
            $this->update(['actual_hours' => $actualHours]);
        }

        $cost = 0;
        if ($this->cost_per_hour && $this->actual_hours) {
            $cost = $this->cost_per_hour * $this->actual_hours;
        }

        $this->update(['total_actual_cost' => $cost]);
        
        return $cost;
    }

    public function checkConflicts(): array
    {
        $conflicts = [];

        // Проверяем пересечения с другими назначениями того же ресурса
        $overlappingAssignments = self::where('resource_type', $this->resource_type)
            ->where('resource_id', $this->resource_id)
            ->where('id', '!=', $this->id)
            ->where('assignment_status', '!=', 'cancelled')
            ->where(function ($query) {
                $query->whereBetween('assignment_start_date', [$this->assignment_start_date, $this->assignment_end_date])
                      ->orWhereBetween('assignment_end_date', [$this->assignment_start_date, $this->assignment_end_date])
                      ->orWhere(function ($q) {
                          $q->where('assignment_start_date', '<=', $this->assignment_start_date)
                            ->where('assignment_end_date', '>=', $this->assignment_end_date);
                      });
            })
            ->with(['task', 'schedule'])
            ->get();

        foreach ($overlappingAssignments as $assignment) {
            $totalAllocation = $this->allocation_percent + $assignment->allocation_percent;
            
            if ($totalAllocation > 100) {
                $conflicts[] = [
                    'type' => 'overallocation',
                    'severity' => $totalAllocation > 150 ? 'critical' : 'warning',
                    'message' => "Перегрузка ресурса: {$totalAllocation}%",
                    'conflicting_task' => $assignment->task->name,
                    'conflicting_schedule' => $assignment->schedule->name,
                    'overlap_start' => max($this->assignment_start_date, $assignment->assignment_start_date),
                    'overlap_end' => min($this->assignment_end_date, $assignment->assignment_end_date),
                ];
            }
        }

        // Обновляем флаг конфликтов
        $hasConflicts = !empty($conflicts);
        $this->update([
            'has_conflicts' => $hasConflicts,
            'conflict_details' => $conflicts,
        ]);

        return $conflicts;
    }

    public function resolveConflicts(string $strategy = 'adjust_allocation'): bool
    {
        if (!$this->has_conflicts) {
            return true;
        }

        switch ($strategy) {
            case 'adjust_allocation':
                return $this->adjustAllocation();
            case 'reschedule':
                return $this->rescheduleAssignment();
            case 'split_assignment':
                return $this->splitAssignment();
            default:
                return false;
        }
    }

    protected function adjustAllocation(): bool
    {
        $conflicts = $this->conflict_details ?? [];
        
        foreach ($conflicts as $conflict) {
            if ($conflict['type'] === 'overallocation') {
                // Уменьшаем процент загрузки до безопасного уровня
                $maxSafeAllocation = 100 - $conflict['conflicting_allocation'] ?? 50;
                $this->update(['allocation_percent' => max($maxSafeAllocation, 25)]);
                break;
            }
        }

        return $this->checkConflicts() === [];
    }

    protected function rescheduleAssignment(): bool
    {
        // Логика перепланирования назначения
        // Находим ближайшее свободное время для ресурса
        // Это сложная логика, требующая анализа всех назначений ресурса
        return false; // Заглушка для сложной логики
    }

    protected function splitAssignment(): bool
    {
        // Логика разделения назначения на несколько частей
        // чтобы избежать конфликтов
        return false; // Заглушка для сложной логики
    }

    public function isAvailable(): bool
    {
        return $this->assignment_status === 'confirmed' && 
               !$this->has_conflicts &&
               $this->assignment_start_date <= now() &&
               $this->assignment_end_date >= now();
    }

    // === SCOPES ===

    public function scopeByType($query, string $type)
    {
        return $query->where('resource_type', $type);
    }

    public function scopeActive($query)
    {
        return $query->whereIn('assignment_status', ['planned', 'confirmed', 'in_progress']);
    }

    public function scopeWithConflicts($query)
    {
        return $query->where('has_conflicts', true);
    }

    public function scopeForDateRange($query, $startDate, $endDate)
    {
        return $query->where(function ($q) use ($startDate, $endDate) {
            $q->whereBetween('assignment_start_date', [$startDate, $endDate])
              ->orWhereBetween('assignment_end_date', [$startDate, $endDate])
              ->orWhere(function ($subQ) use ($startDate, $endDate) {
                  $subQ->where('assignment_start_date', '<=', $startDate)
                       ->where('assignment_end_date', '>=', $endDate);
              });
        });
    }

    public function scopeByResource($query, string $type, int $resourceId)
    {
        return $query->where('resource_type', $type)
                    ->where('resource_id', $resourceId);
    }
} 