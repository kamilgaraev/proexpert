<?php

namespace App\Http\Controllers\Api\V1\Admin\Schedule;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasScheduleOperations;
use App\Repositories\Interfaces\ProjectScheduleRepositoryInterface;
use App\Services\Schedule\CriticalPathService;
use App\Http\Requests\Api\V1\Schedule\CreateProjectScheduleRequest;
use App\Http\Requests\Api\V1\Schedule\UpdateProjectScheduleRequest;
use App\Http\Requests\Api\V1\Schedule\CreateScheduleTaskRequest;
use App\Http\Requests\Api\V1\Schedule\CreateTaskDependencyRequest;
use App\Http\Resources\Api\V1\Schedule\ProjectScheduleResource;
use App\Http\Resources\Api\V1\Schedule\ProjectScheduleCollection;
use App\Http\Resources\Api\V1\Schedule\ScheduleGanttResource;
use App\Models\ScheduleTask;
use App\Models\TaskDependency;
use App\Http\Middleware\ProjectContextMiddleware;
use App\Exceptions\Schedule\ScheduleNotFoundException;
use App\Exceptions\Schedule\CircularDependencyException;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class ProjectScheduleController extends Controller
{
    use HasScheduleOperations;

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
            throw new ScheduleNotFoundException($schedule);
        }

        $this->validateProjectSchedule($project, $scheduleModel);

        // Если запрашивается формат Gantt
        if ($request->get('format') === 'gantt') {
            $scheduleModel->load([
                'rootTasks.childTasks' => function ($query) {
                    $query->orderBy('sort_order');
                },
                'rootTasks.predecessorDependencies',
                'tasks' => function ($query) {
                    $query->orderBy('sort_order');
                },
                'dependencies'
            ]);

            return response()->json([
                'data' => new ScheduleGanttResource($scheduleModel)
            ]);
        }

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
            throw new ScheduleNotFoundException($schedule);
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
            throw new ScheduleNotFoundException($schedule);
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
            throw new ScheduleNotFoundException($schedule);
        }

        $this->validateProjectSchedule($project, $scheduleModel);

        return $this->calculateCriticalPathForSchedule($schedule, function ($scheduleId) use ($project) {
            return $this->scheduleRepository->findForProject($scheduleId, $project);
        });
    }

    /**
     * Сохранить базовый план графика
     */
    public function saveBaseline(Request $request, int $project, int $schedule): JsonResponse
    {
        $scheduleModel = $this->scheduleRepository->findForProject($schedule, $project);

        if (!$scheduleModel) {
            throw new ScheduleNotFoundException($schedule);
        }

        $this->validateProjectSchedule($project, $scheduleModel);

        return $this->saveBaselineForSchedule($schedule, $request, function ($scheduleId) use ($project) {
            return $this->scheduleRepository->findForProject($scheduleId, $project);
        });
    }

    /**
     * Очистить базовый план графика
     */
    public function clearBaseline(Request $request, int $project, int $schedule): JsonResponse
    {
        $scheduleModel = $this->scheduleRepository->findForProject($schedule, $project);

        if (!$scheduleModel) {
            throw new ScheduleNotFoundException($schedule);
        }

        $this->validateProjectSchedule($project, $scheduleModel);

        return $this->clearBaselineForSchedule($schedule, function ($scheduleId) use ($project) {
            return $this->scheduleRepository->findForProject($scheduleId, $project);
        });
    }

    /**
     * Получить задачи расписания
     */
    public function tasks(Request $request, int $project, int $schedule): JsonResponse
    {
        $scheduleModel = $this->scheduleRepository->findForProject($schedule, $project);

        if (!$scheduleModel) {
            throw new ScheduleNotFoundException($schedule);
        }

        $this->validateProjectSchedule($project, $scheduleModel);

        return $this->getScheduleTasks($schedule, function ($scheduleId) use ($project) {
            return $this->scheduleRepository->findForProject($scheduleId, $project);
        });
    }

    /**
     * Создать новую задачу в расписании
     */
    public function storeTask(CreateScheduleTaskRequest $request, int $project, int $schedule): JsonResponse
    {
        $scheduleModel = $this->scheduleRepository->findForProject($schedule, $project);

        if (!$scheduleModel) {
            throw new ScheduleNotFoundException($schedule);
        }

        $this->validateProjectSchedule($project, $scheduleModel);

        $organizationId = $this->getOrganizationId($request);

        return $this->createScheduleTask($schedule, $request, $organizationId, function ($scheduleId) use ($project) {
            return $this->scheduleRepository->findForProject($scheduleId, $project);
        });
    }

    /**
     * Получить зависимости расписания
     */
    public function dependencies(Request $request, int $project, int $schedule): JsonResponse
    {
        $scheduleModel = $this->scheduleRepository->findForProject($schedule, $project);

        if (!$scheduleModel) {
            throw new ScheduleNotFoundException($schedule);
        }

        $this->validateProjectSchedule($project, $scheduleModel);

        return $this->getScheduleDependencies($schedule, function ($scheduleId) use ($project) {
            return $this->scheduleRepository->findForProject($scheduleId, $project);
        });
    }

    /**
     * Создать новую зависимость между задачами
     */
    public function storeDependency(CreateTaskDependencyRequest $request, int $project, int $schedule): JsonResponse
    {
        $scheduleModel = $this->scheduleRepository->findForProject($schedule, $project);

        if (!$scheduleModel) {
            throw new ScheduleNotFoundException($schedule);
        }

        $this->validateProjectSchedule($project, $scheduleModel);

        $organizationId = $this->getOrganizationId($request);

        return $this->createScheduleDependency($schedule, $request, $organizationId, function ($scheduleId) use ($project) {
            return $this->scheduleRepository->findForProject($scheduleId, $project);
        });
    }

    /**
     * Получить конфликты ресурсов в расписании
     */
    public function resourceConflicts(Request $request, int $project, int $schedule): JsonResponse
    {
        $scheduleModel = $this->scheduleRepository->findForProject($schedule, $project);

        if (!$scheduleModel) {
            throw new ScheduleNotFoundException($schedule);
        }

        $this->validateProjectSchedule($project, $scheduleModel);

        $organizationId = $this->getOrganizationId($request);

        return $this->getResourceConflicts($schedule, $organizationId, function ($scheduleId) use ($project) {
            return $this->scheduleRepository->findForProject($scheduleId, $project);
        });
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

