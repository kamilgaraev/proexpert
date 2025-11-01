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
    /**
     * Разрешенные поля для сортировки
     */
    protected const ALLOWED_SORT_FIELDS = [
        'created_at',
        'updated_at',
        'name',
        'planned_start_date',
        'planned_end_date',
        'status',
        'overall_progress_percent',
    ];

    /**
     * Разрешенные направления сортировки
     */
    protected const ALLOWED_SORT_ORDERS = ['asc', 'desc'];

    public function __construct()
    {
        parent::__construct(ProjectSchedule::class);
    }

    /**
     * Валидировать и получить поле для сортировки
     */
    protected function getValidatedSortBy(?string $sortBy): string
    {
        if (!$sortBy || !in_array($sortBy, self::ALLOWED_SORT_FIELDS, true)) {
            return 'created_at';
        }

        return $sortBy;
    }

    /**
     * Валидировать и получить направление сортировки
     */
    protected function getValidatedSortOrder(?string $sortOrder): string
    {
        if (!$sortOrder || !in_array(strtolower($sortOrder), self::ALLOWED_SORT_ORDERS, true)) {
            return 'desc';
        }

        return strtolower($sortOrder);
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

        // Сортировка с валидацией
        $sortBy = $this->getValidatedSortBy($filters['sort_by'] ?? null);
        $sortOrder = $this->getValidatedSortOrder($filters['sort_order'] ?? null);
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
        // Загружаем все задачи одним запросом с необходимыми связями
        $templateTasks = $template->tasks()
            ->with(['childTasks', 'resources', 'milestones'])
            ->orderBy('sort_order')
            ->get();
        
        if ($templateTasks->isEmpty()) {
            return;
        }

        $taskMapping = [];
        $tasksToInsert = [];
        
        // Подготавливаем данные для массовой вставки
        foreach ($templateTasks as $templateTask) {
            $taskData = [
                'schedule_id' => $newSchedule->id,
                'organization_id' => $newSchedule->organization_id,
                'parent_task_id' => null, // Установим позже
                'work_type_id' => $templateTask->work_type_id,
                'assigned_user_id' => $templateTask->assigned_user_id,
                'created_by_user_id' => $templateTask->created_by_user_id,
                'name' => $templateTask->name,
                'description' => $templateTask->description,
                'wbs_code' => $templateTask->wbs_code,
                'task_type' => $templateTask->task_type,
                'planned_start_date' => $templateTask->planned_start_date,
                'planned_end_date' => $templateTask->planned_end_date,
                'planned_duration_days' => $templateTask->planned_duration_days,
                'planned_work_hours' => $templateTask->planned_work_hours,
                'baseline_start_date' => $templateTask->baseline_start_date,
                'baseline_end_date' => $templateTask->baseline_end_date,
                'baseline_duration_days' => $templateTask->baseline_duration_days,
                'status' => 'not_started',
                'priority' => $templateTask->priority,
                'estimated_cost' => $templateTask->estimated_cost,
                'required_resources' => $templateTask->required_resources,
                'constraint_type' => $templateTask->constraint_type,
                'constraint_date' => $templateTask->constraint_date,
                'custom_fields' => $templateTask->custom_fields,
                'notes' => $templateTask->notes,
                'tags' => $templateTask->tags,
                'level' => $templateTask->level,
                'sort_order' => $templateTask->sort_order,
                'progress_percent' => 0,
                'actual_start_date' => null,
                'actual_end_date' => null,
                'actual_duration_days' => null,
                'actual_work_hours' => 0,
                'actual_cost' => 0,
                'is_critical' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $tasksToInsert[] = [
                'data' => $taskData,
                'template_id' => $templateTask->id,
                'template_parent_id' => $templateTask->parent_task_id,
                'resources' => $templateTask->resources,
                'milestones' => $templateTask->milestones,
            ];
        }

        // Массовая вставка задач
        $chunks = array_chunk($tasksToInsert, 100);
        foreach ($chunks as $chunk) {
            $insertData = array_map(fn($item) => $item['data'], $chunk);
            
            // Используем insertGetId для получения ID новых задач
            $insertedIds = [];
            foreach ($insertData as $data) {
                $id = DB::table('schedule_tasks')->insertGetId($data);
                $insertedIds[] = $id;
            }

            // Сохраняем маппинг старых ID на новые
            foreach ($chunk as $index => $item) {
                $taskMapping[$item['template_id']] = $insertedIds[$index];
            }
        }

        // Обновляем parent_task_id массовым обновлением
        $parentUpdates = [];
        foreach ($tasksToInsert as $item) {
            if ($item['template_parent_id'] && isset($taskMapping[$item['template_parent_id']])) {
                $newTaskId = $taskMapping[$item['template_id']] ?? null;
                $newParentId = $taskMapping[$item['template_parent_id']];
                
                if ($newTaskId) {
                    $parentUpdates[$newTaskId] = $newParentId;
                }
            }
        }

        if (!empty($parentUpdates)) {
            foreach ($parentUpdates as $taskId => $parentId) {
                DB::table('schedule_tasks')
                    ->where('id', $taskId)
                    ->update(['parent_task_id' => $parentId]);
            }
        }

        // Копируем зависимости массовой вставкой
        $templateDependencies = $template->dependencies;
        $dependenciesToInsert = [];
        
        foreach ($templateDependencies as $dependency) {
            if (isset($taskMapping[$dependency->predecessor_task_id]) && 
                isset($taskMapping[$dependency->successor_task_id])) {
                
                $dependenciesToInsert[] = [
                    'predecessor_task_id' => $taskMapping[$dependency->predecessor_task_id],
                    'successor_task_id' => $taskMapping[$dependency->successor_task_id],
                    'schedule_id' => $newSchedule->id,
                    'organization_id' => $newSchedule->organization_id,
                    'created_by_user_id' => $dependency->created_by_user_id,
                    'dependency_type' => $dependency->dependency_type,
                    'lag_days' => $dependency->lag_days,
                    'lag_hours' => $dependency->lag_hours,
                    'lag_type' => $dependency->lag_type,
                    'is_critical' => false,
                    'is_hard_constraint' => $dependency->is_hard_constraint,
                    'priority' => $dependency->priority,
                    'description' => $dependency->description,
                    'constraint_reason' => $dependency->constraint_reason,
                    'is_active' => $dependency->is_active,
                    'validation_status' => 'valid',
                    'advanced_settings' => $dependency->advanced_settings,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        if (!empty($dependenciesToInsert)) {
            DB::table('task_dependencies')->insert($dependenciesToInsert);
        }

        // Копируем ресурсы и вехи для каждой задачи
        foreach ($tasksToInsert as $item) {
            $newTaskId = $taskMapping[$item['template_id']] ?? null;
            if (!$newTaskId) {
                continue;
            }

            // Копируем ресурсы задачи
            if ($item['resources']->isNotEmpty()) {
                $resourcesToInsert = [];
                foreach ($item['resources'] as $resource) {
                    $resourcesToInsert[] = [
                        'task_id' => $newTaskId,
                        'schedule_id' => $newSchedule->id,
                        'organization_id' => $newSchedule->organization_id,
                        'assigned_by_user_id' => $resource->assigned_by_user_id,
                        'resource_type' => $resource->resource_type,
                        'resource_id' => $resource->resource_id,
                        'user_id' => $resource->user_id,
                        'material_id' => $resource->material_id,
                        'equipment_name' => $resource->equipment_name,
                        'external_resource_name' => $resource->external_resource_name,
                        'allocated_units' => $resource->allocated_units,
                        'allocated_hours' => $resource->allocated_hours,
                        'allocation_percent' => $resource->allocation_percent,
                        'assignment_start_date' => $resource->assignment_start_date,
                        'assignment_end_date' => $resource->assignment_end_date,
                        'cost_per_hour' => $resource->cost_per_hour,
                        'cost_per_unit' => $resource->cost_per_unit,
                        'assignment_status' => $resource->assignment_status,
                        'priority' => $resource->priority,
                        'role' => $resource->role,
                        'requirements' => json_encode($resource->requirements ?? []),
                        'working_calendar' => json_encode($resource->working_calendar ?? []),
                        'daily_working_hours' => $resource->daily_working_hours,
                        'has_conflicts' => false,
                        'conflict_details' => null,
                        'notes' => $resource->notes,
                        'allocation_details' => json_encode($resource->allocation_details ?? []),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
                if (!empty($resourcesToInsert)) {
                    DB::table('task_resources')->insert($resourcesToInsert);
                }
            }

            // Копируем вехи задачи
            if ($item['milestones']->isNotEmpty()) {
                $milestonesToInsert = [];
                foreach ($item['milestones'] as $milestone) {
                    $milestonesToInsert[] = [
                        'task_id' => $newTaskId,
                        'schedule_id' => $newSchedule->id,
                        'organization_id' => $newSchedule->organization_id,
                        'name' => $milestone->name,
                        'description' => $milestone->description,
                        'target_date' => $milestone->target_date,
                        'status' => 'pending',
                        'is_critical' => false,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
                if (!empty($milestonesToInsert)) {
                    DB::table('task_milestones')->insert($milestonesToInsert);
                }
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

    public function getPaginatedForProject(
        int $projectId,
        int $perPage = 15,
        array $filters = []
    ): LengthAwarePaginator {
        $query = $this->model->newQuery()
            ->where('project_id', $projectId)
            ->with(['project', 'createdBy'])
            ->withCount(['tasks', 'dependencies', 'resources']);

        // Применяем фильтры
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
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

        // Сортировка с валидацией
        $sortBy = $this->getValidatedSortBy($filters['sort_by'] ?? null);
        $sortOrder = $this->getValidatedSortOrder($filters['sort_order'] ?? null);
        $query->orderBy($sortBy, $sortOrder);

        return $query->paginate($perPage);
    }

    public function findForProject(int $scheduleId, int $projectId): ?ProjectSchedule
    {
        return $this->model->newQuery()
            ->where('id', $scheduleId)
            ->where('project_id', $projectId)
            ->first();
    }
} 