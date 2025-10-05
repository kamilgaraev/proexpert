<?php

namespace App\Repositories\Schedule;

use App\Models\ProjectSchedule;
use App\Repositories\BaseRepository;
use App\Repositories\Interfaces\ProjectScheduleRepositoryInterface;
use App\Enums\Schedule\ScheduleStatusEnum;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ProjectScheduleRepository extends BaseRepository implements ProjectScheduleRepositoryInterface
{
    public function __construct()
    {
        parent::__construct(ProjectSchedule::class);
    }

    public function getPaginatedForOrganization(
        int $organizationId,
        int $perPage = 15,
        array $filters = []
    ): LengthAwarePaginator {
        $query = $this->model->newQuery()
            ->where('organization_id', $organizationId)
            ->with(['project', 'createdBy'])
            ->withCount(['tasks', 'dependencies', 'resources']);

        // Применяем фильтры
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['project_id'])) {
            $query->where('project_id', $filters['project_id']);
        }

        if (!empty($filters['is_template'])) {
            $query->where('is_template', $filters['is_template']);
        }

        if (!empty($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('name', 'like', '%' . $filters['search'] . '%')
                  ->orWhere('description', 'like', '%' . $filters['search'] . '%');
            });
        }

        if (!empty($filters['date_from'])) {
            $query->where('planned_start_date', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->where('planned_end_date', '<=', $filters['date_to']);
        }

        if (!empty($filters['critical_path_calculated'])) {
            $query->where('critical_path_calculated', $filters['critical_path_calculated']);
        }

        // Сортировка
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortOrder = $filters['sort_order'] ?? 'desc';
        $query->orderBy($sortBy, $sortOrder);

        return $query->paginate($perPage);
    }

    public function getActiveForProject(int $projectId): Collection
    {
        return $this->model->newQuery()
            ->where('project_id', $projectId)
            ->active()
            ->with(['tasks' => function ($query) {
                $query->orderBy('sort_order');
            }])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function getTemplatesForOrganization(int $organizationId): Collection
    {
        return $this->model->newQuery()
            ->where('organization_id', $organizationId)
            ->template()
            ->with(['createdBy'])
            ->withCount(['tasks'])
            ->orderBy('template_name')
            ->get();
    }

    public function createFromTemplate(
        int $templateId,
        int $projectId,
        array $overrides = []
    ): ProjectSchedule {
        return DB::transaction(function () use ($templateId, $projectId, $overrides) {
            $template = $this->model->findOrFail($templateId);
            
            if (!$template->is_template) {
                throw new \InvalidArgumentException('Указанный график не является шаблоном');
            }

            // Создаем новый график на основе шаблона
            $scheduleData = $template->toArray();
            unset($scheduleData['id'], $scheduleData['created_at'], $scheduleData['updated_at']);
            
            $scheduleData = array_merge($scheduleData, $overrides, [
                'project_id' => $projectId,
                'is_template' => false,
                'template_name' => null,
                'template_description' => null,
                'status' => ScheduleStatusEnum::DRAFT,
                'critical_path_calculated' => false,
                'critical_path_updated_at' => null,
                'critical_path_duration_days' => null,
                'overall_progress_percent' => 0,
                'total_actual_cost' => 0,
                'actual_start_date' => null,
                'actual_end_date' => null,
            ]);

            $newSchedule = $this->create($scheduleData);

            // Копируем задачи из шаблона
            $this->copyTasksFromTemplate($template, $newSchedule);

            return $newSchedule;
        });
    }

    protected function copyTasksFromTemplate(ProjectSchedule $template, ProjectSchedule $newSchedule): void
    {
        $templateTasks = $template->tasks()->with(['childTasks', 'resources', 'milestones'])->get();
        $taskMapping = [];

        // Сначала создаем все задачи
        foreach ($templateTasks as $templateTask) {
            $taskData = $templateTask->toArray();
            unset($taskData['id'], $taskData['created_at'], $taskData['updated_at']);
            
            $taskData['schedule_id'] = $newSchedule->id;
            $taskData['parent_task_id'] = null; // Установим позже
            $taskData['status'] = 'not_started';
            $taskData['progress_percent'] = 0;
            $taskData['actual_start_date'] = null;
            $taskData['actual_end_date'] = null;
            $taskData['actual_duration_days'] = null;
            $taskData['actual_work_hours'] = 0;
            $taskData['actual_cost'] = 0;
            $taskData['is_critical'] = false;

            $newTask = $newSchedule->tasks()->create($taskData);
            $taskMapping[$templateTask->id] = $newTask->id;
        }

        // Устанавливаем родительские связи
        foreach ($templateTasks as $templateTask) {
            if ($templateTask->parent_task_id && isset($taskMapping[$templateTask->parent_task_id])) {
                $newTaskId = $taskMapping[$templateTask->id];
                $newParentId = $taskMapping[$templateTask->parent_task_id];
                
                DB::table('schedule_tasks')
                    ->where('id', $newTaskId)
                    ->update(['parent_task_id' => $newParentId]);
            }
        }

        // Копируем зависимости
        $templateDependencies = $template->dependencies;
        foreach ($templateDependencies as $dependency) {
            if (isset($taskMapping[$dependency->predecessor_task_id]) && 
                isset($taskMapping[$dependency->successor_task_id])) {
                
                $dependencyData = $dependency->toArray();
                unset($dependencyData['id'], $dependencyData['created_at'], $dependencyData['updated_at']);
                
                $dependencyData['schedule_id'] = $newSchedule->id;
                $dependencyData['predecessor_task_id'] = $taskMapping[$dependency->predecessor_task_id];
                $dependencyData['successor_task_id'] = $taskMapping[$dependency->successor_task_id];
                
                $newSchedule->dependencies()->create($dependencyData);
            }
        }
    }

    public function findNeedingCriticalPathRecalculation(): Collection
    {
        return $this->model->newQuery()
            ->active()
            ->where(function ($query) {
                $query->where('critical_path_calculated', false)
                      ->orWhereNull('critical_path_updated_at')
                      ->orWhereRaw('
                          critical_path_updated_at < (
                              SELECT MAX(GREATEST(
                                  COALESCE(st.updated_at, "1970-01-01"),
                                  COALESCE(td.updated_at, "1970-01-01")
                              ))
                              FROM schedule_tasks st
                              LEFT JOIN task_dependencies td ON td.schedule_id = project_schedules.id
                              WHERE st.schedule_id = project_schedules.id
                          )
                      ');
            })
            ->get();
    }

    public function getOrganizationStats(int $organizationId): array
    {
        $stats = $this->model->newQuery()
            ->where('organization_id', $organizationId)
            ->selectRaw("
                COUNT(*) as total_schedules,
                COUNT(CASE WHEN status = 'active' THEN 1 END) as active_schedules,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_schedules,
                COUNT(CASE WHEN is_template = true THEN 1 END) as templates,
                AVG(overall_progress_percent) as avg_progress,
                COUNT(CASE WHEN critical_path_calculated = true THEN 1 END) as with_critical_path
            ")
            ->first();

        $overdueStats = $this->model->newQuery()
            ->where('organization_id', $organizationId)
            ->active()
            ->whereHas('tasks', function ($query) {
                $query->overdue();
            })
            ->count();

        return [
            'total_schedules' => $stats->total_schedules ?? 0,
            'active_schedules' => $stats->active_schedules ?? 0,
            'completed_schedules' => $stats->completed_schedules ?? 0,
            'templates' => $stats->templates ?? 0,
            'avg_progress' => round($stats->avg_progress ?? 0, 2),
            'with_critical_path' => $stats->with_critical_path ?? 0,
            'with_overdue_tasks' => $overdueStats,
        ];
    }

    public function getWithOverdueTasks(int $organizationId): Collection
    {
        return $this->model->newQuery()
            ->where('organization_id', $organizationId)
            ->active()
            ->whereHas('tasks', function ($query) {
                $query->overdue();
            })
            ->with(['project', 'tasks' => function ($query) {
                $query->overdue()->with(['assignedUser']);
            }])
            ->get();
    }

    public function findByDateRange(
        int $organizationId,
        string $startDate,
        string $endDate
    ): Collection {
        return $this->model->newQuery()
            ->where('organization_id', $organizationId)
            ->where(function ($query) use ($startDate, $endDate) {
                $query->whereBetween('planned_start_date', [$startDate, $endDate])
                      ->orWhereBetween('planned_end_date', [$startDate, $endDate])
                      ->orWhere(function ($q) use ($startDate, $endDate) {
                          $q->where('planned_start_date', '<=', $startDate)
                            ->where('planned_end_date', '>=', $endDate);
                      });
            })
            ->with(['project'])
            ->orderBy('planned_start_date')
            ->get();
    }

    public function getRecentlyUpdated(int $organizationId, int $limit = 10): Collection
    {
        return $this->model->newQuery()
            ->where('organization_id', $organizationId)
            ->with(['project', 'createdBy'])
            ->orderBy('updated_at', 'desc')
            ->limit($limit)
            ->get();
    }

    public function saveBaseline(int $scheduleId, int $userId): bool
    {
        $schedule = $this->model->findOrFail($scheduleId);
        return $schedule->saveBaseline(\App\Models\User::find($userId));
    }

    public function clearBaseline(int $scheduleId): bool
    {
        $schedule = $this->model->findOrFail($scheduleId);
        return $schedule->clearBaseline();
    }

    public function getCriticalSchedules(int $organizationId): Collection
    {
        return $this->model->newQuery()
            ->where('organization_id', $organizationId)
            ->active()
            ->where(function ($query) {
                $query->whereRaw('planned_end_date < CURDATE()')
                      ->orWhereHas('tasks', function ($q) {
                          $q->where('is_critical', true)
                            ->where('status', '!=', 'completed');
                      });
            })
            ->with(['project', 'tasks' => function ($query) {
                $query->critical()->active();
            }])
            ->get();
    }

    public function archiveCompleted(int $organizationId, int $daysOld = 90): int
    {
        return $this->model->newQuery()
            ->where('organization_id', $organizationId)
            ->where('status', ScheduleStatusEnum::COMPLETED)
            ->where('updated_at', '<', now()->subDays($daysOld))
            ->delete();
    }

    public function getWithResourceConflicts(int $organizationId): Collection
    {
        return $this->model->newQuery()
            ->where('organization_id', $organizationId)
            ->active()
            ->whereHas('resources', function ($query) {
                $query->where('has_conflicts', true);
            })
            ->with(['project', 'resources' => function ($query) {
                $query->where('has_conflicts', true)->with(['task', 'user', 'material']);
            }])
            ->get();
    }

    public function findForOrganization(int $scheduleId, int $organizationId): ?ProjectSchedule
    {
        return $this->model->newQuery()
            ->where('id', $scheduleId)
            ->where('organization_id', $organizationId)
            ->first();
    }
} 