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
        
        if (empty($conflicts)) {
            return true;
        }

        // Собираем все конфликты перезагрузки
        $overallocationConflicts = array_filter($conflicts, function ($conflict) {
            return ($conflict['type'] ?? '') === 'overallocation';
        });

        if (empty($overallocationConflicts)) {
            return true;
        }

        // Вычисляем максимальную безопасную загрузку
        // Берем минимальное доступное значение из всех конфликтов
        $maxSafeAllocation = 100;
        
        foreach ($overallocationConflicts as $conflict) {
            $conflictingAllocation = $conflict['conflicting_allocation'] ?? 0;
            // Безопасная загрузка = 100% - сумма загрузок других назначений
            $safeAllocation = max(0, 100 - $conflictingAllocation);
            $maxSafeAllocation = min($maxSafeAllocation, $safeAllocation);
        }

        // Минимальная загрузка 25%, чтобы не обнулить назначение полностью
        $newAllocation = max(25, min($maxSafeAllocation, 100));
        
        // Обновляем только если изменилось
        if ($newAllocation != $this->allocation_percent) {
            $this->update(['allocation_percent' => $newAllocation]);
        }

        // Проверяем, что конфликты исчезли после корректировки
        return $this->checkConflicts() === [];
    }

    protected function rescheduleAssignment(): bool
    {
        if (!$this->assignment_start_date || !$this->assignment_end_date) {
            return false;
        }

        // Получаем все назначения ресурса в диапазоне дат
        $conflictingAssignments = $this->getConflictingAssignments();
        
        if (empty($conflictingAssignments)) {
            return true;
        }

        // Вычисляем общую длительность назначения
        $duration = $this->assignment_start_date->diffInDays($this->assignment_end_date) + 1;
        
        // Находим ближайшее свободное окно после последнего конфликтующего назначения
        $lastConflictEnd = $conflictingAssignments->max(function ($assignment) {
            return $assignment->assignment_end_date ?: $assignment->assignment_start_date;
        });
        
        if (!$lastConflictEnd) {
            return false;
        }

        // Сдвигаем назначение на день после последнего конфликта
        $newStartDate = $lastConflictEnd->copy()->addDay();
        $newEndDate = $newStartDate->copy()->addDays($duration - 1);

        // Проверяем, что новые даты не выходят за границы задачи
        if ($this->task) {
            $taskEnd = $this->task->planned_end_date ?? $this->task->actual_end_date;
            
            if ($taskEnd && $newEndDate > $taskEnd) {
                // Если не помещается в даты задачи, сдвигаем в начало
                $taskStart = $this->task->planned_start_date ?? $this->task->actual_start_date;
                
                if (!$taskStart) {
                    return false;
                }

                // Ищем свободное окно перед первым конфликтом
                $firstConflictStart = $conflictingAssignments->min(function ($assignment) {
                    return $assignment->assignment_start_date;
                });

                if ($firstConflictStart && $taskStart < $firstConflictStart) {
                    $newEndDate = $firstConflictStart->copy()->subDay();
                    $newStartDate = $newEndDate->copy()->subDays($duration - 1);
                    
                    if ($newStartDate < $taskStart) {
                        return false; // Не помещается в доступное окно
                    }
                } else {
                    return false; // Нет свободного места
                }
            }
        }

        // Обновляем даты назначения
        $this->update([
            'assignment_start_date' => $newStartDate->toDateString(),
            'assignment_end_date' => $newEndDate->toDateString(),
        ]);

        // Проверяем, что конфликты исчезли
        return $this->checkConflicts() === [];
    }

    protected function splitAssignment(): bool
    {
        if (!$this->assignment_start_date || !$this->assignment_end_date) {
            return false;
        }

        // Получаем конфликтующие назначения
        $conflictingAssignments = $this->getConflictingAssignments();
        
        if (empty($conflictingAssignments)) {
            return true;
        }

        // Сортируем конфликты по дате начала
        $sortedConflicts = $conflictingAssignments->sortBy(function ($assignment) {
            return $assignment->assignment_start_date;
        });

        $segments = [];
        $currentStart = $this->assignment_start_date->copy();
        $originalEnd = $this->assignment_end_date->copy();
        $allocationPercent = $this->allocation_percent ?? 100;

        // Разделяем назначение на сегменты вокруг конфликтов
        foreach ($sortedConflicts as $conflict) {
            $conflictStart = $conflict->assignment_start_date;
            $conflictEnd = $conflict->assignment_end_date ?? $conflictStart;

            // Сегмент до конфликта
            if ($currentStart < $conflictStart) {
                $segmentEnd = $conflictStart->copy()->subDay();
                if ($segmentEnd >= $currentStart) {
                    $segments[] = [
                        'start' => $currentStart->copy(),
                        'end' => $segmentEnd,
                        'allocation_percent' => $allocationPercent,
                    ];
                }
            }

            // Пропускаем период конфликта
            $currentStart = $conflictEnd->copy()->addDay();
        }

        // Последний сегмент после всех конфликтов
        if ($currentStart <= $originalEnd) {
            $segments[] = [
                'start' => $currentStart->copy(),
                'end' => $originalEnd->copy(),
                'allocation_percent' => $allocationPercent,
            ];
        }

        if (empty($segments)) {
            return false; // Невозможно разделить без конфликтов
        }

        // Если только один сегмент, просто обновляем текущее назначение
        if (count($segments) === 1) {
            $segment = $segments[0];
            $this->update([
                'assignment_start_date' => $segment['start']->toDateString(),
                'assignment_end_date' => $segment['end']->toDateString(),
            ]);
            
            return $this->checkConflicts() === [];
        }

        // Обновляем текущее назначение первым сегментом
        $firstSegment = array_shift($segments);
        $this->update([
            'assignment_start_date' => $firstSegment['start']->toDateString(),
            'assignment_end_date' => $firstSegment['end']->toDateString(),
            'allocation_percent' => $firstSegment['allocation_percent'],
        ]);

        // Создаем дополнительные назначения для остальных сегментов
        foreach ($segments as $segment) {
            static::create([
                'task_id' => $this->task_id,
                'schedule_id' => $this->schedule_id,
                'organization_id' => $this->organization_id,
                'resource_type' => $this->resource_type,
                'resource_id' => $this->resource_id,
                'user_id' => $this->user_id,
                'material_id' => $this->material_id,
                'equipment_name' => $this->equipment_name,
                'external_resource_name' => $this->external_resource_name,
                'allocated_units' => $this->allocated_units,
                'allocated_hours' => $this->allocated_hours,
                'allocation_percent' => $segment['allocation_percent'],
                'assignment_start_date' => $segment['start']->toDateString(),
                'assignment_end_date' => $segment['end']->toDateString(),
                'cost_per_hour' => $this->cost_per_hour,
                'cost_per_unit' => $this->cost_per_unit,
                'assignment_status' => $this->assignment_status,
                'priority' => $this->priority,
                'role' => $this->role,
                'requirements' => $this->requirements,
                'working_calendar' => $this->working_calendar,
                'daily_working_hours' => $this->daily_working_hours,
                'assigned_by_user_id' => $this->assigned_by_user_id,
            ]);
        }

        // Проверяем, что конфликты исчезли
        return $this->checkConflicts() === [];
    }

    /**
     * Получить конфликтующие назначения ресурса
     */
    protected function getConflictingAssignments(): \Illuminate\Support\Collection
    {
        if (!$this->assignment_start_date || !$this->assignment_end_date) {
            return collect();
        }

        $query = static::where('id', '!=', $this->id)
            ->where('has_conflicts', false) // Исключаем уже конфликтующие
            ->where(function ($q) {
                // Фильтруем по типу ресурса
                if ($this->user_id) {
                    $q->where('user_id', $this->user_id);
                }
                if ($this->material_id) {
                    $q->orWhere('material_id', $this->material_id);
                }
                if ($this->equipment_name) {
                    $q->orWhere('equipment_name', $this->equipment_name);
                }
            })
            ->where(function ($q) {
                // Находим пересекающиеся периоды
                $q->whereBetween('assignment_start_date', [
                    $this->assignment_start_date,
                    $this->assignment_end_date
                ])
                ->orWhereBetween('assignment_end_date', [
                    $this->assignment_start_date,
                    $this->assignment_end_date
                ])
                ->orWhere(function ($subQ) {
                    $subQ->where('assignment_start_date', '<=', $this->assignment_start_date)
                          ->where('assignment_end_date', '>=', $this->assignment_end_date);
                });
            });

        return $query->get();
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