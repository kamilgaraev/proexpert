<?php

namespace App\BusinessModules\Features\AIAssistant\Actions\Analysis;

use App\Models\Project;
use App\Models\ScheduleTask;
use Illuminate\Support\Facades\DB;

class CollectScheduleDataAction
{
    /**
     * Собрать данные по графику работ проекта
     *
     * @param int $projectId
     * @param int $organizationId
     * @return array
     */
    public function execute(int $projectId, int $organizationId): array
    {
        $project = Project::where('id', $projectId)
            ->where('organization_id', $organizationId)
            ->firstOrFail();

        // Задачи из ProjectSchedule
        $schedule = $project->schedule;
        $tasks = $schedule ? $schedule->tasks()->with('dependencies')->get() : collect();

        $now = now();
        
        // Анализ задач
        $totalTasks = $tasks->count();
        $completedTasks = $tasks->where('status', 'completed')->count();
        $inProgressTasks = $tasks->where('status', 'in_progress')->count();
        $pendingTasks = $tasks->where('status', 'pending')->count();
        
        // Просроченные задачи
        $overdueTasks = $tasks->filter(function ($task) use ($now) {
            return $task->end_date && 
                   $task->end_date->isPast() && 
                   $task->status !== 'completed';
        });

        // Задачи, заканчивающиеся скоро (в течение недели)
        $upcomingTasks = $tasks->filter(function ($task) use ($now) {
            return $task->end_date && 
                   $task->end_date->isFuture() &&
                   $task->end_date->diffInDays($now) <= 7 &&
                   $task->status !== 'completed';
        });

        // Критический путь (задачи с зависимостями)
        $criticalPathTasks = $this->identifyCriticalPath($tasks);

        // Статистика по времени
        $completionPercentage = $totalTasks > 0 
            ? round(($completedTasks / $totalTasks) * 100, 2) 
            : 0;

        return [
            'project_name' => $project->name,
            'project_dates' => [
                'start_date' => $project->start_date?->format('Y-m-d'),
                'end_date' => $project->end_date?->format('Y-m-d'),
                'total_days' => $project->start_date && $project->end_date 
                    ? $project->start_date->diffInDays($project->end_date) 
                    : 0,
                'remaining_days' => $project->end_date 
                    ? $now->diffInDays($project->end_date, false) 
                    : 0,
            ],
            'tasks_summary' => [
                'total' => $totalTasks,
                'completed' => $completedTasks,
                'in_progress' => $inProgressTasks,
                'pending' => $pendingTasks,
                'overdue' => $overdueTasks->count(),
                'upcoming' => $upcomingTasks->count(),
                'completion_percentage' => $completionPercentage,
            ],
            'overdue_tasks' => $overdueTasks->map(function ($task) use ($now) {
                return [
                    'id' => $task->id,
                    'name' => $task->name,
                    'end_date' => $task->end_date->format('Y-m-d'),
                    'days_overdue' => $now->diffInDays($task->end_date),
                    'assignee' => $task->assignee_name ?? 'Не назначено',
                ];
            })->values()->toArray(),
            'upcoming_tasks' => $upcomingTasks->map(function ($task) use ($now) {
                return [
                    'id' => $task->id,
                    'name' => $task->name,
                    'end_date' => $task->end_date->format('Y-m-d'),
                    'days_until_deadline' => $task->end_date->diffInDays($now),
                    'status' => $task->status,
                ];
            })->values()->toArray(),
            'critical_path' => $criticalPathTasks,
            'schedule_health' => $this->assessScheduleHealth($overdueTasks->count(), $completionPercentage),
        ];
    }

    /**
     * Идентифицировать критический путь
     */
    private function identifyCriticalPath($tasks): array
    {
        // Упрощенный алгоритм: задачи с блокирующими зависимостями
        $criticalTasks = $tasks->filter(function ($task) {
            return $task->dependencies && $task->dependencies->count() > 0;
        });

        return $criticalTasks->map(function ($task) {
            return [
                'id' => $task->id,
                'name' => $task->name,
                'start_date' => $task->start_date?->format('Y-m-d'),
                'end_date' => $task->end_date?->format('Y-m-d'),
                'status' => $task->status,
                'dependencies_count' => $task->dependencies->count(),
            ];
        })->values()->toArray();
    }

    /**
     * Оценить здоровье графика
     */
    private function assessScheduleHealth(int $overdueCount, float $completionPercentage): string
    {
        if ($overdueCount > 5 || ($overdueCount > 0 && $completionPercentage < 30)) {
            return 'critical';
        }
        
        if ($overdueCount > 2) {
            return 'warning';
        }
        
        return 'good';
    }
}

