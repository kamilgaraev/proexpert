<?php

namespace App\Http\Controllers\Api\V1\Schedule;

use App\Http\Controllers\Controller;
use App\Repositories\Interfaces\ProjectScheduleRepositoryInterface;
use App\Services\Schedule\CriticalPathService;
use App\Http\Requests\Api\V1\Schedule\CreateProjectScheduleRequest;
use App\Http\Requests\Api\V1\Schedule\UpdateProjectScheduleRequest;
use App\Http\Requests\Api\V1\Schedule\CreateScheduleTaskRequest;
use App\Http\Resources\Api\V1\Schedule\ProjectScheduleResource;
use App\Http\Resources\Api\V1\Schedule\ProjectScheduleCollection;
use App\Models\ScheduleTask;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ProjectScheduleController extends Controller
{
    public function __construct(
        protected ProjectScheduleRepositoryInterface $scheduleRepository,
        protected CriticalPathService $criticalPathService
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
        $data = $request->validated();
        $data['organization_id'] = $this->getOrganizationId($request);
        $data['created_by_user_id'] = $request->user()->id;

        // Устанавливаем значения по умолчанию
        $data['status'] = $data['status'] ?? 'draft';
        $data['overall_progress_percent'] = 0;
        $data['critical_path_calculated'] = false;

        $schedule = $this->scheduleRepository->create($data);

        return response()->json([
            'message' => 'График проекта успешно создан',
            'data' => new ProjectScheduleResource($schedule->load(['project', 'createdBy']))
        ], 201);
    }

    /**
     * Получить детальную информацию о графике
     */
    public function show(int $id, Request $request): JsonResponse
    {
        $schedule = $this->scheduleRepository->findForOrganization(
            $id,
            $this->getOrganizationId($request)
        );

        if (!$schedule) {
            return response()->json(['message' => 'График не найден'], 404);
        }

        return response()->json([
            'data' => new ProjectScheduleResource($schedule->load(['project', 'createdBy', 'tasks', 'dependencies', 'resources']))
        ]);
    }

    /**
     * Обновить график проекта
     */
    public function update(int $id, UpdateProjectScheduleRequest $request): JsonResponse
    {
        $schedule = $this->scheduleRepository->findForOrganization(
            $id,
            $this->getOrganizationId($request)
        );

        if (!$schedule) {
            return response()->json(['message' => 'График не найден'], 404);
        }

        $data = $request->validated();
        
        // Не позволяем изменять organization_id и created_by_user_id через update
        unset($data['organization_id'], $data['created_by_user_id']);
        
        if (isset($data['is_template']) && $data['is_template']) {
            unset($data['project_id']); // Шаблоны не привязаны к проектам
        }

        $this->scheduleRepository->update($schedule->id, $data);
        $schedule = $this->scheduleRepository->findForOrganization($schedule->id, $this->getOrganizationId($request));

        return response()->json([
            'message' => 'График проекта обновлен',
            'data' => new ProjectScheduleResource($schedule->load(['project', 'createdBy']))
        ]);
    }

    /**
     * Удалить график проекта
     */
    public function destroy(int $id, Request $request): JsonResponse
    {
        $schedule = $this->scheduleRepository->findForOrganization(
            $id,
            $this->getOrganizationId($request)
        );

        if (!$schedule) {
            return response()->json(['message' => 'График не найден'], 404);
        }

        $this->scheduleRepository->delete($schedule->id);

        return response()->json(['message' => 'График проекта удален'], 200);
    }

    /**
     * Рассчитать критический путь для графика
     */
    public function calculateCriticalPath(int $id, Request $request): JsonResponse
    {
        $schedule = $this->scheduleRepository->findForOrganization(
            $id,
            $this->getOrganizationId($request)
        );

        if (!$schedule) {
            return response()->json(['message' => 'График не найден'], 404);
        }

        try {
            $criticalPath = $this->criticalPathService->calculateCriticalPath($schedule);
            
            // Обновляем флаг что критический путь рассчитан
            $this->scheduleRepository->update($schedule->id, [
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
            ], 500);
        }
    }

    /**
     * Сохранить базовый план графика
     */
    public function saveBaseline(int $id, Request $request): JsonResponse
    {
        $schedule = $this->scheduleRepository->findForOrganization(
            $id,
            $this->getOrganizationId($request)
        );

        if (!$schedule) {
            return response()->json(['message' => 'График не найден'], 404);
        }

        try {
            $this->scheduleRepository->saveBaseline($schedule->id, $request->user()->id);

            return response()->json([
                'message' => 'Базовый план сохранен'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Ошибка при сохранении базового плана: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Очистить базовый план графика
     */
    public function clearBaseline(int $id, Request $request): JsonResponse
    {
        $schedule = $this->scheduleRepository->findForOrganization(
            $id,
            $this->getOrganizationId($request)
        );

        if (!$schedule) {
            return response()->json(['message' => 'График не найден'], 404);
        }

        try {
            $this->scheduleRepository->clearBaseline($schedule->id);

            return response()->json([
                'message' => 'Базовый план очищен'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Ошибка при очистке базового плана: ' . $e->getMessage()
            ], 500);
        }
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
        $stats = $this->scheduleRepository->getOrganizationStats(
            $this->getOrganizationId($request)
        );

        return response()->json(['data' => $stats]);
    }

    /**
     * Получить просроченные графики
     */
    public function overdue(Request $request): JsonResponse
    {
        $overdue = $this->scheduleRepository->getWithOverdueTasks(
            $this->getOrganizationId($request)
        );

        return response()->json([
            'data' => ProjectScheduleResource::collection($overdue)
        ]);
    }

    /**
     * Получить недавние графики
     */
    public function recent(Request $request): JsonResponse
    {
        $recent = $this->scheduleRepository->getRecentlyUpdated(
            $this->getOrganizationId($request),
            $request->get('limit', 10)
        );

        return response()->json([
            'data' => ProjectScheduleResource::collection($recent)
        ]);
    }

    /**
     * Получить задачи расписания
     */
    public function tasks(int $id, Request $request): JsonResponse
    {
        $schedule = $this->scheduleRepository->findForOrganization(
            $id,
            $this->getOrganizationId($request)
        );

        if (!$schedule) {
            return response()->json(['message' => 'График не найден'], 404);
        }

        $tasks = $schedule->tasks()->with(['assignedUser', 'workType'])->get();

        return response()->json([
            'data' => $tasks
        ]);
    }

    /**
     * Получить зависимости расписания
     */
    public function dependencies(int $id, Request $request): JsonResponse
    {
        $schedule = $this->scheduleRepository->findForOrganization(
            $id,
            $this->getOrganizationId($request)
        );

        if (!$schedule) {
            return response()->json(['message' => 'График не найден'], 404);
        }

        $dependencies = $schedule->dependencies()
            ->with(['predecessorTask', 'successorTask'])
            ->get();

        return response()->json([
            'data' => $dependencies
        ]);
    }

    /**
     * Создать новую задачу в расписании
     */
    public function storeTask(int $id, CreateScheduleTaskRequest $request): JsonResponse
    {
        $schedule = $this->scheduleRepository->findForOrganization(
            $id,
            $this->getOrganizationId($request)
        );

        if (!$schedule) {
            return response()->json(['message' => 'График не найден'], 404);
        }

        $data = $request->validated();
        $data['schedule_id'] = $schedule->id;
        $data['organization_id'] = $this->getOrganizationId($request);
        $data['created_by_user_id'] = $request->user()->id;

        try {
            $task = ScheduleTask::create($data);
            
            // Загружаем связанные данные для ответа
            $task->load(['assignedUser', 'workType', 'parentTask']);

            return response()->json([
                'message' => 'Задача успешно создана',
                'data' => $task
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Ошибка при создании задачи: ' . $e->getMessage()
            ], 500);
        }
    }
} 