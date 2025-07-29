<?php

namespace App\Services\Schedule;

use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use App\Models\TaskDependency;
use App\Enums\Schedule\DependencyTypeEnum;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class CriticalPathService
{
    protected array $tasks = [];
    protected array $dependencies = [];
    protected array $taskMap = [];
    protected array $criticalPath = [];

    public function calculateCriticalPath(ProjectSchedule $schedule): array
    {
        try {
            Log::info("Начинаем расчет критического пути для графика {$schedule->id}");
            
            return DB::transaction(function () use ($schedule) {
                $this->initializeData($schedule);
                
                if (empty($this->tasks)) {
                    Log::warning("Нет задач для расчета критического пути в графике {$schedule->id}");
                    return ['duration' => 0, 'tasks' => [], 'critical_path' => []];
                }

                // Проверка на циклические зависимости
                if ($this->hasCycles()) {
                    throw new \Exception('Обнаружены циклические зависимости в графике');
                }

                // Forward Pass - расчет ранних дат
                $this->calculateEarlyDates();
                
                // Backward Pass - расчет поздних дат
                $this->calculateLateDates();
                
                // Расчет резервов времени
                $this->calculateFloats();
                
                // Определение критического пути
                $this->identifyCriticalPath();
                
                // Обновляем данные в базе
                $this->updateTasksInDatabase();
                
                $duration = $this->getCriticalPathDuration();
                $schedule->markCriticalPathCalculated($duration);
                
                Log::info("Критический путь рассчитан. Длительность: {$duration} дней");
                
                return [
                    'duration' => $duration,
                    'tasks' => $this->getCriticalTasks(),
                    'critical_path' => $this->criticalPath,
                    'statistics' => $this->getStatistics()
                ];
            });
        } catch (\Exception $e) {
            Log::error("Ошибка при расчете критического пути: " . $e->getMessage());
            throw $e;
        }
    }

    protected function initializeData(ProjectSchedule $schedule): void
    {
        // Загружаем задачи
        $tasks = $schedule->tasks()
            ->where('task_type', '!=', 'container')
            ->get();
            
        foreach ($tasks as $task) {
            $this->tasks[$task->id] = [
                'id' => $task->id,
                'name' => $task->name,
                'duration' => $task->planned_duration_days,
                'early_start' => 0,
                'early_finish' => 0,
                'late_start' => 0,
                'late_finish' => 0,
                'total_float' => 0,
                'free_float' => 0,
                'is_critical' => false,
                'predecessors' => [],
                'successors' => [],
                'model' => $task
            ];
            $this->taskMap[$task->id] = $task->id;
        }

        // Загружаем зависимости
        $dependencies = $schedule->dependencies()
            ->where('is_active', true)
            ->where('validation_status', 'valid')
            ->get();
            
        foreach ($dependencies as $dependency) {
            $predId = $dependency->predecessor_task_id;
            $succId = $dependency->successor_task_id;
            
            if (isset($this->tasks[$predId]) && isset($this->tasks[$succId])) {
                $this->dependencies[] = [
                    'predecessor' => $predId,
                    'successor' => $succId,
                    'type' => $dependency->dependency_type,
                    'lag' => $dependency->getTotalLag(),
                    'model' => $dependency
                ];
                
                $this->tasks[$predId]['successors'][] = $succId;
                $this->tasks[$succId]['predecessors'][] = $predId;
            }
        }
    }

    protected function hasCycles(): bool
    {
        $visited = [];
        $recursionStack = [];
        
        foreach (array_keys($this->tasks) as $taskId) {
            if (!isset($visited[$taskId])) {
                if ($this->detectCycleRecursive($taskId, $visited, $recursionStack)) {
                    return true;
                }
            }
        }
        
        return false;
    }

    protected function detectCycleRecursive(int $taskId, array &$visited, array &$recursionStack): bool
    {
        $visited[$taskId] = true;
        $recursionStack[$taskId] = true;
        
        foreach ($this->tasks[$taskId]['successors'] as $successorId) {
            if (!isset($visited[$successorId])) {
                if ($this->detectCycleRecursive($successorId, $visited, $recursionStack)) {
                    return true;
                }
            } elseif (isset($recursionStack[$successorId]) && $recursionStack[$successorId]) {
                return true;
            }
        }
        
        unset($recursionStack[$taskId]);
        return false;
    }

    protected function calculateEarlyDates(): void
    {
        // Находим начальные задачи (без предшественников)
        $startTasks = array_filter($this->tasks, function ($task) {
            return empty($task['predecessors']);
        });

        // Устанавливаем начальную дату для стартовых задач
        foreach ($startTasks as $taskId => $task) {
            $this->tasks[$taskId]['early_start'] = 0;
            $this->tasks[$taskId]['early_finish'] = $task['duration'];
        }

        // Топологическая сортировка и расчет ранних дат
        $processed = [];
        $queue = array_keys($startTasks);

        while (!empty($queue)) {
            $currentId = array_shift($queue);
            
            if (isset($processed[$currentId])) {
                continue;
            }

            // Проверяем, что все предшественники обработаны
            $allPredecessorsProcessed = true;
            foreach ($this->tasks[$currentId]['predecessors'] as $predId) {
                if (!isset($processed[$predId])) {
                    $allPredecessorsProcessed = false;
                    break;
                }
            }

            if (!$allPredecessorsProcessed) {
                $queue[] = $currentId; // Возвращаем в конец очереди
                continue;
            }

            // Рассчитываем раннее начало как максимум от всех предшественников
            $earlyStart = 0;
            foreach ($this->dependencies as $dependency) {
                if ($dependency['successor'] === $currentId) {
                    $predId = $dependency['predecessor'];
                    $constraintDate = $this->calculateConstraintDate(
                        $predId,
                        $dependency['type'],
                        $dependency['lag']
                    );
                    $earlyStart = max($earlyStart, $constraintDate);
                }
            }

            $this->tasks[$currentId]['early_start'] = $earlyStart;
            $this->tasks[$currentId]['early_finish'] = $earlyStart + $this->tasks[$currentId]['duration'];

            $processed[$currentId] = true;

            // Добавляем последователей в очередь
            foreach ($this->tasks[$currentId]['successors'] as $successorId) {
                if (!isset($processed[$successorId])) {
                    $queue[] = $successorId;
                }
            }
        }
    }

    protected function calculateLateDates(): void
    {
        // Находим конечные задачи (без последователей)
        $endTasks = array_filter($this->tasks, function ($task) {
            return empty($task['successors']);
        });

        // Находим максимальную раннюю дату окончания
        $projectFinish = 0;
        foreach ($this->tasks as $task) {
            $projectFinish = max($projectFinish, $task['early_finish']);
        }

        // Устанавливаем позднее окончание для конечных задач
        foreach ($endTasks as $taskId => $task) {
            $this->tasks[$taskId]['late_finish'] = $projectFinish;
            $this->tasks[$taskId]['late_start'] = $projectFinish - $task['duration'];
        }

        // Обратный проход для расчета поздних дат
        $processed = [];
        $queue = array_keys($endTasks);

        while (!empty($queue)) {
            $currentId = array_shift($queue);
            
            if (isset($processed[$currentId])) {
                continue;
            }

            // Проверяем, что все последователи обработаны
            $allSuccessorsProcessed = true;
            foreach ($this->tasks[$currentId]['successors'] as $succId) {
                if (!isset($processed[$succId])) {
                    $allSuccessorsProcessed = false;
                    break;
                }
            }

            if (!$allSuccessorsProcessed) {
                $queue[] = $currentId;
                continue;
            }

            // Рассчитываем позднее окончание как минимум от всех последователей
            if (!empty($this->tasks[$currentId]['successors'])) {
                $lateFinish = PHP_INT_MAX;
                
                foreach ($this->dependencies as $dependency) {
                    if ($dependency['predecessor'] === $currentId) {
                        $succId = $dependency['successor'];
                        $constraintDate = $this->calculateReverseConstraintDate(
                            $succId,
                            $dependency['type'],
                            $dependency['lag']
                        );
                        $lateFinish = min($lateFinish, $constraintDate);
                    }
                }

                $this->tasks[$currentId]['late_finish'] = $lateFinish;
                $this->tasks[$currentId]['late_start'] = $lateFinish - $this->tasks[$currentId]['duration'];
            }

            $processed[$currentId] = true;

            // Добавляем предшественников в очередь
            foreach ($this->tasks[$currentId]['predecessors'] as $predId) {
                if (!isset($processed[$predId])) {
                    $queue[] = $predId;
                }
            }
        }
    }

    protected function calculateConstraintDate(int $predId, DependencyTypeEnum $type, float $lag): int
    {
        $pred = $this->tasks[$predId];
        
        return match($type) {
            DependencyTypeEnum::FINISH_TO_START => $pred['early_finish'] + $lag,
            DependencyTypeEnum::START_TO_START => $pred['early_start'] + $lag,
            DependencyTypeEnum::FINISH_TO_FINISH => $pred['early_finish'] + $lag,
            DependencyTypeEnum::START_TO_FINISH => $pred['early_start'] + $lag,
            default => $pred['early_finish'] + $lag,
        };
    }

    protected function calculateReverseConstraintDate(int $succId, DependencyTypeEnum $type, float $lag): int
    {
        $succ = $this->tasks[$succId];
        
        return match($type) {
            DependencyTypeEnum::FINISH_TO_START => $succ['late_start'] - $lag,
            DependencyTypeEnum::START_TO_START => $succ['late_start'] - $lag,
            DependencyTypeEnum::FINISH_TO_FINISH => $succ['late_finish'] - $lag,
            DependencyTypeEnum::START_TO_FINISH => $succ['late_finish'] - $lag,
            default => $succ['late_start'] - $lag,
        };
    }

    protected function calculateFloats(): void
    {
        foreach ($this->tasks as $taskId => &$task) {
            // Общий резерв = позднее начало - раннее начало
            $task['total_float'] = $task['late_start'] - $task['early_start'];
            
            // Свободный резерв
            $freeFloat = PHP_INT_MAX;
            if (!empty($task['successors'])) {
                foreach ($task['successors'] as $succId) {
                    $successor = $this->tasks[$succId];
                    $freeFloat = min($freeFloat, $successor['early_start'] - $task['early_finish']);
                }
            } else {
                $freeFloat = $task['total_float'];
            }
            
            $task['free_float'] = max(0, $freeFloat);
        }
    }

    protected function identifyCriticalPath(): void
    {
        // Задачи на критическом пути имеют нулевой общий резерв
        foreach ($this->tasks as $taskId => &$task) {
            $task['is_critical'] = $task['total_float'] <= 0;
            if ($task['is_critical']) {
                $this->criticalPath[] = $taskId;
            }
        }
    }

    protected function updateTasksInDatabase(): void
    {
        foreach ($this->tasks as $taskId => $taskData) {
            ScheduleTask::where('id', $taskId)->update([
                'early_start_date' => Carbon::now()->addDays($taskData['early_start'])->toDateString(),
                'early_finish_date' => Carbon::now()->addDays($taskData['early_finish'])->toDateString(),
                'late_start_date' => Carbon::now()->addDays($taskData['late_start'])->toDateString(),
                'late_finish_date' => Carbon::now()->addDays($taskData['late_finish'])->toDateString(),
                'total_float_days' => (int) $taskData['total_float'],
                'free_float_days' => (int) $taskData['free_float'],
                'is_critical' => $taskData['is_critical'],
            ]);
        }

        // Обновляем зависимости на критическом пути
        foreach ($this->dependencies as $dependency) {
            $predId = $dependency['predecessor'];
            $succId = $dependency['successor'];
            $isCritical = $this->tasks[$predId]['is_critical'] && $this->tasks[$succId]['is_critical'];
            
            TaskDependency::where('predecessor_task_id', $predId)
                ->where('successor_task_id', $succId)
                ->update(['is_critical' => $isCritical]);
        }
    }

    protected function getCriticalPathDuration(): int
    {
        if (empty($this->tasks)) {
            return 0;
        }

        return max(array_column($this->tasks, 'early_finish'));
    }

    protected function getCriticalTasks(): array
    {
        return array_values(array_filter($this->tasks, function ($task) {
            return $task['is_critical'];
        }));
    }

    protected function getStatistics(): array
    {
        $totalTasks = count($this->tasks);
        $criticalTasks = count($this->criticalPath);
        
        return [
            'total_tasks' => $totalTasks,
            'critical_tasks' => $criticalTasks,
            'critical_percentage' => $totalTasks > 0 ? round(($criticalTasks / $totalTasks) * 100, 2) : 0,
            'project_duration' => $this->getCriticalPathDuration(),
            'total_dependencies' => count($this->dependencies),
        ];
    }
} 