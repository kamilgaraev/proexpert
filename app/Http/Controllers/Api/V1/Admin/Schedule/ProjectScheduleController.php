<?php

namespace App\Http\Controllers\Api\V1\Admin\Schedule;

use App\Http\Controllers\Controller;
use App\Repositories\Interfaces\ProjectScheduleRepositoryInterface;
use App\Services\Schedule\CriticalPathService;
use App\Http\Requests\Api\V1\Schedule\CreateProjectScheduleRequest;
use App\Http\Requests\Api\V1\Schedule\UpdateProjectScheduleRequest;
use App\Http\Requests\Api\V1\Schedule\CreateScheduleTaskRequest;
use App\Http\Requests\Api\V1\Schedule\CreateTaskDependencyRequest;
use App\Http\Resources\Api\V1\Schedule\ProjectScheduleResource;
use App\Http\Resources\Api\V1\Schedule\ProjectScheduleCollection;
use App\Models\ScheduleTask;
use App\Models\TaskDependency;
use App\Http\Middleware\ProjectContextMiddleware;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class ProjectScheduleController extends Controller
{
    public function __construct(
        protected ProjectScheduleRepositoryInterface $scheduleRepository,
        protected CriticalPathService $criticalPathService
    ) {}

    /**
     * Получить список графиков для проекта с фильтрацией и пагинацией
     */
    public function index(Request $request, int $project): JsonResponse
    {
        $perPage = min($request->get('per_page', 15), 100);
        
        $filters = $request->only([
            'status', 'is_template', 'search', 
            'date_from', 'date_to', 'critical_path_calculated',
            'sort_by', 'sort_order'
        ]);

        $schedules = $this->scheduleRepository->getPaginatedForProject(
            $project,
            $perPage,
            $filters
        );

        return response()->json(new ProjectScheduleCollection($schedules));
    }

    /**
     * Создать новый график для проекта
     */
    public function store(CreateProjectScheduleRequest $request, int $project): JsonResponse
    {
        $organizationId = $this->getOrganizationId($request);
        $data = $request->validated();
        
        // Устанавливаем project_id из URL
        $data['project_id'] = $project;
        $data['organization_id'] = $organizationId;
        $data['created_by_user_id'] = $request->user()->id;

        // Устанавливаем значения по умолчанию
        $data['status'] = $data['status'] ?? 'draft';
        $data['overall_progress_percent'] = 0;
        $data['critical_path_calculated'] = false;

        $schedule = $this->scheduleRepository->create($data);

        return response()->json([
            'message' => 'График проекта успешно создан',
            'data' => new ProjectScheduleResource($schedule->load(['project', 'createdBy']))
        ], Response::HTTP_CREATED);
    }

    /**
     * Получить детальную информацию о графике
     */
    public function show(Request $request, int $project, int $schedule): JsonResponse
    {
        $scheduleModel = $this->scheduleRepository->findForProject($schedule, $project);

        if (!$scheduleModel) {
            return response()->json(['message' => 'График не найден'], Response::HTTP_NOT_FOUND);
        }

        $this->validateProjectSchedule($project, $scheduleModel);

        return response()->json([
            'data' => new ProjectScheduleResource($scheduleModel->load(['project', 'createdBy', 'tasks', 'dependencies', 'resources']))
        ]);
    }

    /**
     * Обновить график проекта
     */
    public function update(UpdateProjectScheduleRequest $request, int $project, int $schedule): JsonResponse
    {
        $scheduleModel = $this->scheduleRepository->findForProject($schedule, $project);

        if (!$scheduleModel) {
            return response()->json(['message' => 'График не найден'], Response::HTTP_NOT_FOUND);
        }

        $this->validateProjectSchedule($project, $scheduleModel);

        $data = $request->validated();
        
        // Не позволяем изменять project_id, organization_id и created_by_user_id через update
        unset($data['project_id'], $data['organization_id'], $data['created_by_user_id']);
        
        // В project-based контексте графики всегда привязаны к проекту
        if (isset($data['is_template']) && $data['is_template']) {
            unset($data['is_template']); // Нельзя сделать график шаблоном в project-based контексте
        }

        $this->scheduleRepository->update($scheduleModel->id, $data);
        $scheduleModel = $this->scheduleRepository->findForProject($scheduleModel->id, $project);

        return response()->json([
            'message' => 'График проекта обновлен',
            'data' => new ProjectScheduleResource($scheduleModel->load(['project', 'createdBy']))
        ]);
    }

    /**
     * Удалить график проекта
     */
    public function destroy(Request $request, int $project, int $schedule): JsonResponse
    {
        $scheduleModel = $this->scheduleRepository->findForProject($schedule, $project);

        if (!$scheduleModel) {
            return response()->json(['message' => 'График не найден'], Response::HTTP_NOT_FOUND);
        }

        $this->validateProjectSchedule($project, $scheduleModel);

        $this->scheduleRepository->delete($scheduleModel->id);

        return response()->json(['message' => 'График проекта удален'], Response::HTTP_OK);
    }

    /**
     * Рассчитать критический путь для графика
     */
    public function calculateCriticalPath(Request $request, int $project, int $schedule): JsonResponse
    {
        $scheduleModel = $this->scheduleRepository->findForProject($schedule, $project);

        if (!$scheduleModel) {
            return response()->json(['message' => 'График не найден'], Response::HTTP_NOT_FOUND);
        }

        $this->validateProjectSchedule($project, $scheduleModel);

        try {
            $criticalPath = $this->criticalPathService->calculateCriticalPath($scheduleModel);
            
            // Обновляем флаг что критический путь рассчитан
            $this->scheduleRepository->update($scheduleModel->id, [
                'critical_path_calculated' => true,
                'critical_path_duration_days' => $criticalPath['duration']
            ]);

            return response()->json([
                'message' => 'Критический путь рассчитан',
                'data' => $criticalPath
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Ошибка при расчете критического пути: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Сохранить базовый план графика
     */
    public function saveBaseline(Request $request, int $project, int $schedule): JsonResponse
    {
        $scheduleModel = $this->scheduleRepository->findForProject($schedule, $project);

        if (!$scheduleModel) {
            return response()->json(['message' => 'График не найден'], Response::HTTP_NOT_FOUND);
        }

        $this->validateProjectSchedule($project, $scheduleModel);

        try {
            $this->scheduleRepository->saveBaseline($scheduleModel->id, $request->user()->id);

            return response()->json([
                'message' => 'Базовый план сохранен'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Ошибка при сохранении базового плана: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Очистить базовый план графика
     */
    public function clearBaseline(Request $request, int $project, int $schedule): JsonResponse
    {
        $scheduleModel = $this->scheduleRepository->findForProject($schedule, $project);

        if (!$scheduleModel) {
            return response()->json(['message' => 'График не найден'], Response::HTTP_NOT_FOUND);
        }

        $this->validateProjectSchedule($project, $scheduleModel);

        try {
            $this->scheduleRepository->clearBaseline($scheduleModel->id);

            return response()->json([
                'message' => 'Базовый план очищен'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Ошибка при очистке базового плана: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Получить задачи расписания
     */
    public function tasks(Request $request, int $project, int $schedule): JsonResponse
    {
        $scheduleModel = $this->scheduleRepository->findForProject($schedule, $project);

        if (!$scheduleModel) {
            return response()->json(['message' => 'График не найден'], Response::HTTP_NOT_FOUND);
        }

        $this->validateProjectSchedule($project, $scheduleModel);

        $tasks = $scheduleModel->tasks()->with(['assignedUser', 'workType'])->get();

        return response()->json([
            'data' => $tasks
        ]);
    }

    /**
     * Создать новую задачу в расписании
     */
    public function storeTask(CreateScheduleTaskRequest $request, int $project, int $schedule): JsonResponse
    {
        $scheduleModel = $this->scheduleRepository->findForProject($schedule, $project);

        if (!$scheduleModel) {
            return response()->json(['message' => 'График не найден'], Response::HTTP_NOT_FOUND);
        }

        $this->validateProjectSchedule($project, $scheduleModel);

        $data = $request->validated();
        $data['schedule_id'] = $scheduleModel->id;
        $data['organization_id'] = $this->getOrganizationId($request);
        $data['created_by_user_id'] = $request->user()->id;

        try {
            $task = ScheduleTask::create($data);
            
            // Загружаем связанные данные для ответа
            $task->load(['assignedUser', 'workType', 'parentTask']);

            return response()->json([
                'message' => 'Задача успешно создана',
                'data' => $task
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Ошибка при создании задачи: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Получить зависимости расписания
     */
    public function dependencies(Request $request, int $project, int $schedule): JsonResponse
    {
        $scheduleModel = $this->scheduleRepository->findForProject($schedule, $project);

        if (!$scheduleModel) {
            return response()->json(['message' => 'График не найден'], Response::HTTP_NOT_FOUND);
        }

        $this->validateProjectSchedule($project, $scheduleModel);

        $dependencies = $scheduleModel->dependencies()
            ->with(['predecessorTask', 'successorTask'])
            ->get();

        return response()->json([
            'data' => $dependencies
        ]);
    }

    /**
     * Создать новую зависимость между задачами
     */
    public function storeDependency(CreateTaskDependencyRequest $request, int $project, int $schedule): JsonResponse
    {
        $scheduleModel = $this->scheduleRepository->findForProject($schedule, $project);

        if (!$scheduleModel) {
            return response()->json(['message' => 'График не найден'], Response::HTTP_NOT_FOUND);
        }

        $this->validateProjectSchedule($project, $scheduleModel);

        $data = $request->validated();
        $data['schedule_id'] = $scheduleModel->id;
        $data['organization_id'] = $this->getOrganizationId($request);
        $data['created_by_user_id'] = $request->user()->id;
        
        // Устанавливаем значения по умолчанию
        $data['is_active'] = true;
        $data['validation_status'] = 'valid';

        try {
            $dependency = TaskDependency::create($data);
            
            // Загружаем связанные данные для ответа
            $dependency->load(['predecessorTask', 'successorTask', 'createdBy']);

            return response()->json([
                'message' => 'Зависимость между задачами успешно создана',
                'data' => [
                    'id' => $dependency->id,
                    'dependency_type' => $dependency->dependency_type->value,
                    'dependency_type_label' => $dependency->dependency_type->label(),
                    'lag_days' => $dependency->lag_days,
                    'lag_hours' => $dependency->lag_hours,
                    'lag_type' => $dependency->lag_type,
                    'description' => $dependency->description,
                    'is_hard_constraint' => $dependency->is_hard_constraint,
                    'priority' => $dependency->priority,
                    'is_active' => $dependency->is_active,
                    'validation_status' => $dependency->validation_status,
                    'created_at' => $dependency->created_at,
                    'predecessor_task' => [
                        'id' => $dependency->predecessorTask->id,
                        'name' => $dependency->predecessorTask->name,
                        'planned_start_date' => $dependency->predecessorTask->planned_start_date,
                        'planned_end_date' => $dependency->predecessorTask->planned_end_date,
                    ],
                    'successor_task' => [
                        'id' => $dependency->successorTask->id,
                        'name' => $dependency->successorTask->name,
                        'planned_start_date' => $dependency->successorTask->planned_start_date,
                        'planned_end_date' => $dependency->successorTask->planned_end_date,
                    ],
                    'created_by' => [
                        'id' => $dependency->createdBy->id,
                        'name' => $dependency->createdBy->name,
                        'email' => $dependency->createdBy->email,
                    ]
                ]
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Ошибка при создании зависимости: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Получить конфликты ресурсов в расписании
     */
    public function resourceConflicts(Request $request, int $project, int $schedule): JsonResponse
    {
        $scheduleModel = $this->scheduleRepository->findForProject($schedule, $project);

        if (!$scheduleModel) {
            return response()->json(['message' => 'График не найден'], Response::HTTP_NOT_FOUND);
        }

        $this->validateProjectSchedule($project, $scheduleModel);

        try {
            // Загружаем детальную информацию о конфликтах
            $scheduleModel->load([
                'tasks' => function ($query) {
                    $query->whereHas('resources', function ($q) {
                        $q->where('is_overallocated', true);
                    })->with(['resources', 'assignedUser']);
                },
                'resources' => function ($query) {
                    $query->where('is_overallocated', true);
                }
            ]);

            $hasConflicts = $scheduleModel->tasks->isNotEmpty() || $scheduleModel->resources->isNotEmpty();

            if (!$hasConflicts) {
                return response()->json([
                    'data' => [],
                    'meta' => [
                        'conflicts_count' => 0,
                        'has_conflicts' => false,
                        'message' => 'Конфликтов ресурсов не обнаружено'
                    ]
                ]);
            }

            return response()->json([
                'data' => [
                    'schedule_id' => $scheduleModel->id,
                    'schedule_name' => $scheduleModel->name,
                    'conflicted_tasks' => $scheduleModel->tasks,
                    'conflicted_resources' => $scheduleModel->resources,
                ],
                'meta' => [
                    'conflicts_count' => $scheduleModel->tasks->count() + $scheduleModel->resources->count(),
                    'has_conflicts' => true,
                    'message' => 'Обнаружены конфликты ресурсов'
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Ошибка при получении конфликтов ресурсов: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Получить ID организации из запроса
     */
    private function getOrganizationId(Request $request): int
    {
        $organization = ProjectContextMiddleware::getOrganization($request);
        
        if ($organization) {
            return $organization->id;
        }
        
        // Fallback на старый способ получения организации
        $user = $request->user();
        $organizationId = $user->current_organization_id ?? $user->organization_id;
        
        if (!$organizationId) {
            abort(400, 'Не определена организация пользователя');
        }
        
        return (int) $organizationId;
    }

    /**
     * Проверить принадлежность графика к проекту
     */
    private function validateProjectSchedule(int $project, $schedule): void
    {
        if ($schedule->project_id !== $project) {
            abort(Response::HTTP_NOT_FOUND, 'График принадлежит другому проекту');
        }
    }
}

