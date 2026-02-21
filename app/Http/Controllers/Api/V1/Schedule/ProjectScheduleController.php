<?php

namespace App\Http\Controllers\Api\V1\Schedule;

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
use App\Services\Schedule\ScheduleTaskService;
use App\Models\ScheduleTask;
use App\Models\TaskDependency;
use App\Enums\Schedule\PriorityEnum;
use App\Exceptions\Schedule\ScheduleNotFoundException;
use App\Exceptions\Schedule\CircularDependencyException;
use App\Exceptions\Schedule\ResourceConflictException;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class ProjectScheduleController extends Controller
{
    use HasScheduleOperations;

    public function __construct(
        protected ProjectScheduleRepositoryInterface $scheduleRepository,
        protected CriticalPathService $criticalPathService,
        protected ScheduleTaskService $taskService
    ) {}

    /**
     * Получить ID организации из запроса
     */
    private function getOrganizationId(Request $request): int
    {
        $user = $request->user();
        $organizationId = $user->current_organization_id ?? $user->organization_id;
        
        if (!$organizationId) {
            abort(400, 'Не определена организация пользователя');
        }
        
        return (int) $organizationId;
    }

    /**
     * Получить список графиков для организации с фильтрацией и пагинацией
     */
    public function index(Request $request): JsonResponse
    {
        $organizationId = $this->getOrganizationId($request);
        $perPage = min($request->get('per_page', 15), 100);
        
        $filters = $request->only([
            'status', 'project_id', 'is_template', 'search', 
            'date_from', 'date_to', 'critical_path_calculated',
            'sort_by', 'sort_order'
        ]);

        $schedules = $this->scheduleRepository->getPaginatedForOrganization(
            $organizationId,
            $perPage,
            $filters
        );

        return response()->json(new ProjectScheduleCollection($schedules));
    }

    /**
     * Создать новый график проекта
     */
    public function store(CreateProjectScheduleRequest $request): JsonResponse
    {
        return $this->createSchedule($request, $this->getOrganizationId($request));
    }

    /**
     * Получить детальную информацию о графике
     */
    public function show(string $id, Request $request): JsonResponse
    {
        $schedule = $this->scheduleRepository->findForOrganization(
            (int) $id,
            $this->getOrganizationId($request)
        );

        if (!$schedule) {
            throw new ScheduleNotFoundException((int) $id);
        }

        // Если запрашивается формат Gantt
        if ($request->get('format') === 'gantt') {
            // Проверяем, нужно ли инициализировать sort_order
            $needsReorder = $schedule->tasks()->where('sort_order', 0)->exists();
            if ($needsReorder) {
                $this->taskService->reorderTasks($schedule);
                // Перезагружаем график чтобы получить обновленные данные
                $schedule = $this->scheduleRepository->findForOrganization((int) $id, $this->getOrganizationId($request));
            }

            $schedule->load([
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
                'data' => new ScheduleGanttResource($schedule)
            ]);
        }

        return response()->json([
            'data' => new ProjectScheduleResource($schedule->load(['project', 'createdBy', 'tasks', 'dependencies', 'resources']))
        ]);
    }

    /**
     * Обновить график проекта
     */
    public function update(string $id, UpdateProjectScheduleRequest $request): JsonResponse
    {
        $organizationId = $this->getOrganizationId($request);
        
        return $this->updateSchedule((int) $id, $request, function ($scheduleId) use ($organizationId) {
            return $this->scheduleRepository->findForOrganization($scheduleId, $organizationId);
        });
    }

    /**
     * Удалить график проекта
     */
    public function destroy(string $id, Request $request): JsonResponse
    {
        $organizationId = $this->getOrganizationId($request);
        
        return $this->deleteSchedule((int) $id, function ($scheduleId) use ($organizationId) {
            return $this->scheduleRepository->findForOrganization($scheduleId, $organizationId);
        });
    }

    /**
     * Рассчитать критический путь для графика
     */
    public function calculateCriticalPath(string $id, Request $request): JsonResponse
    {
        $organizationId = $this->getOrganizationId($request);
        
        return $this->calculateCriticalPathForSchedule((int) $id, function ($scheduleId) use ($organizationId) {
            return $this->scheduleRepository->findForOrganization($scheduleId, $organizationId);
        });
    }

    /**
     * Сохранить базовый план графика
     */
    public function saveBaseline(string $id, Request $request): JsonResponse
    {
        $organizationId = $this->getOrganizationId($request);
        
        return $this->saveBaselineForSchedule((int) $id, $request, function ($scheduleId) use ($organizationId) {
            return $this->scheduleRepository->findForOrganization($scheduleId, $organizationId);
        });
    }

    /**
     * Очистить базовый план графика
     */
    public function clearBaseline(string $id, Request $request): JsonResponse
    {
        $organizationId = $this->getOrganizationId($request);
        
        return $this->clearBaselineForSchedule((int) $id, function ($scheduleId) use ($organizationId) {
            return $this->scheduleRepository->findForOrganization($scheduleId, $organizationId);
        });
    }

    /**
     * Получить шаблоны графиков
     */
    public function templates(Request $request): JsonResponse
    {
        $templates = $this->scheduleRepository->getTemplatesForOrganization(
            $this->getOrganizationId($request)
        );

        return response()->json([
            'data' => ProjectScheduleResource::collection($templates)
        ]);
    }

    /**
     * Создать график из шаблона
     */
    public function createFromTemplate(Request $request): JsonResponse
    {
        $request->validate([
            'template_id' => 'required|integer|exists:project_schedules,id',
            'project_id' => 'required|integer|exists:projects,id',
            'name' => 'required|string|max:255',
            'planned_start_date' => 'required|date',
            'planned_end_date' => 'required|date|after:planned_start_date',
        ]);

        try {
            $validatedData = $request->validated();

            $schedule = $this->scheduleRepository->createFromTemplate(
                $request->template_id,
                $request->project_id,
                [
                    'name' => $request->name,
                    'planned_start_date' => $request->planned_start_date,
                    'planned_end_date' => $request->planned_end_date,
                    'organization_id' => $this->getOrganizationId($request),
                    'created_by_user_id' => $request->user()->id,
                ]
            );

            return response()->json([
                'message' => 'График создан из шаблона',
                'data' => new ProjectScheduleResource($schedule->load(['project', 'createdBy']))
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Ошибка при создании графика из шаблона: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить статистику по графикам
     */
    public function statistics(Request $request): JsonResponse
    {
        Log::info('[ProjectScheduleController] statistics called', [
            'user_id' => $request->user()?->id,
            'organization_id' => $request->user()?->current_organization_id,
            'url' => $request->fullUrl(),
            'method' => $request->method(),
        ]);

        try {
            $organizationId = $this->getOrganizationId($request);
            
            Log::info('[ProjectScheduleController] getting stats for organization', [
                'organization_id' => $organizationId
            ]);
            
            $stats = $this->scheduleRepository->getOrganizationStats($organizationId);
            
            Log::info('[ProjectScheduleController] stats retrieved successfully', [
                'stats' => $stats
            ]);

            return response()->json(['data' => $stats]);
        } catch (\Exception $e) {
            Log::error('[ProjectScheduleController] error in statistics', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Получить просроченные графики
     */
    public function overdue(Request $request): JsonResponse
    {
        Log::info('[ProjectScheduleController] overdue called', [
            'user_id' => $request->user()?->id,
            'organization_id' => $request->user()?->current_organization_id,
            'url' => $request->fullUrl(),
        ]);

        try {
            $organizationId = $this->getOrganizationId($request);
            
            Log::info('[ProjectScheduleController] getting overdue for organization', [
                'organization_id' => $organizationId
            ]);
            
            $overdue = $this->scheduleRepository->getWithOverdueTasks($organizationId);
            
            Log::info('[ProjectScheduleController] overdue retrieved successfully', [
                'count' => $overdue->count()
            ]);

            return response()->json([
                'data' => ProjectScheduleResource::collection($overdue)
            ]);
        } catch (\Exception $e) {
            Log::error('[ProjectScheduleController] error in overdue', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Получить недавние графики
     */
    public function recent(Request $request): JsonResponse
    {
        Log::info('[ProjectScheduleController] recent called', [
            'user_id' => $request->user()?->id,
            'organization_id' => $request->user()?->current_organization_id,
            'url' => $request->fullUrl(),
            'limit' => $request->get('limit', 10),
        ]);

        try {
            $organizationId = $this->getOrganizationId($request);
            $limit = $request->get('limit', 10);
            
            Log::info('[ProjectScheduleController] getting recent for organization', [
                'organization_id' => $organizationId,
                'limit' => $limit
            ]);
            
            $recent = $this->scheduleRepository->getRecentlyUpdated($organizationId, $limit);
            
            Log::info('[ProjectScheduleController] recent retrieved successfully', [
                'count' => $recent->count()
            ]);

            return response()->json([
                'data' => ProjectScheduleResource::collection($recent)
            ]);
        } catch (\Exception $e) {
            Log::error('[ProjectScheduleController] error in recent', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Получить все графики с конфликтами ресурсов
     */
    public function allResourceConflicts(Request $request): JsonResponse
    {
        $conflictedSchedules = $this->scheduleRepository->getWithResourceConflicts(
            $this->getOrganizationId($request)
        );

        return response()->json([
            'data' => ProjectScheduleResource::collection($conflictedSchedules),
            'meta' => [
                'total_schedules_with_conflicts' => $conflictedSchedules->count(),
                'message' => $conflictedSchedules->isEmpty() 
                    ? 'Конфликтов ресурсов не обнаружено' 
                    : 'Найдены графики с конфликтами ресурсов'
            ]
        ]);
    }

    /**
     * Получить задачи расписания
     */
    public function tasks(string $id, Request $request): JsonResponse
    {
        $organizationId = $this->getOrganizationId($request);
        
        return $this->getScheduleTasks((int) $id, function ($scheduleId) use ($organizationId) {
            return $this->scheduleRepository->findForOrganization($scheduleId, $organizationId);
        });
    }

    /**
     * Получить зависимости расписания
     */
    public function dependencies(string $id, Request $request): JsonResponse
    {
        $organizationId = $this->getOrganizationId($request);
        
        return $this->getScheduleDependencies((int) $id, function ($scheduleId) use ($organizationId) {
            return $this->scheduleRepository->findForOrganization($scheduleId, $organizationId);
        });
    }

    /**
     * Создать новую зависимость между задачами
     */
    public function storeDependency(string $id, CreateTaskDependencyRequest $request): JsonResponse
    {
        $organizationId = $this->getOrganizationId($request);
        
        return $this->createScheduleDependency((int) $id, $request, $organizationId, function ($scheduleId) use ($organizationId) {
            return $this->scheduleRepository->findForOrganization($scheduleId, $organizationId);
        });
    }

    /**
     * Получить конфликты ресурсов в расписании
     */
    public function resourceConflicts(string $id, Request $request): JsonResponse
    {
        $organizationId = $this->getOrganizationId($request);
        
        return $this->getResourceConflicts((int) $id, $organizationId, function ($scheduleId) use ($organizationId) {
            return $this->scheduleRepository->findForOrganization($scheduleId, $organizationId);
        });
    }

    /**
     * Создать новую задачу в расписании
     */
    public function storeTask(string $id, CreateScheduleTaskRequest $request): JsonResponse
    {
        $organizationId = $this->getOrganizationId($request);
        
        return $this->createScheduleTask((int) $id, $request, $organizationId, function ($scheduleId) use ($organizationId) {
            return $this->scheduleRepository->findForOrganization($scheduleId, $organizationId);
        });
    }
} 