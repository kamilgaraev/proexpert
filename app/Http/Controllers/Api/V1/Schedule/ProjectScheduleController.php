<?php

namespace App\Http\Controllers\Api\V1\Schedule;

use App\Http\Controllers\Controller;
use App\Repositories\Interfaces\ProjectScheduleRepositoryInterface;
use App\Services\Schedule\CriticalPathService;
use App\Http\Requests\Api\V1\Schedule\CreateProjectScheduleRequest;
use App\Http\Requests\Api\V1\Schedule\UpdateProjectScheduleRequest;
use App\Http\Resources\Api\V1\Schedule\ProjectScheduleResource;
use App\Http\Resources\Api\V1\Schedule\ProjectScheduleCollection;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ProjectScheduleController extends Controller
{
    public function __construct(
        protected ProjectScheduleRepositoryInterface $scheduleRepository,
        protected CriticalPathService $criticalPathService
    ) {}

    /**
     * Получить список графиков для организации с фильтрацией и пагинацией
     */
    public function index(Request $request): JsonResponse
    {
        $organizationId = $request->user()->organization_id;
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
        $data['organization_id'] = $request->user()->organization_id;
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
            $request->user()->organization_id
        );

        if (!$schedule) {
            return response()->json(['message' => 'График не найден'], 404);
        }

        $schedule->load(['project', 'createdBy', 'baselineSavedBy', 'tasks.assignedUser', 'dependencies', 'resources']);

        return response()->json([
            'data' => new ProjectScheduleResource($schedule)
        ]);
    }

    /**
     * Обновить График проекта
     */
    public function update(UpdateProjectScheduleRequest $request, int $id): JsonResponse
    {
        $schedule = $this->scheduleRepository->findForOrganization(
            $id,
            $request->user()->organization_id
        );

        if (!$schedule) {
            return response()->json(['message' => 'График не найден'], 404);
        }

        $data = $request->validated();
        
        // Если изменились даты - сбрасываем расчет критического пути
        if (isset($data['planned_start_date']) || isset($data['planned_end_date'])) {
            $data['critical_path_calculated'] = false;
            $data['critical_path_updated_at'] = null;
        }

        $schedule = $this->scheduleRepository->update($schedule->id, $data);

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
            $request->user()->organization_id
        );

        if (!$schedule) {
            return response()->json(['message' => 'График не найден'], 404);
        }

        // Проверяем, можно ли удалить график
        if ($schedule->status === 'active' && $schedule->overall_progress_percent > 0) {
            return response()->json([
                'message' => 'Нельзя удалить активный график с выполненными задачами'
            ], 422);
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
            $request->user()->organization_id
        );

        if (!$schedule) {
            return response()->json(['message' => 'График не найден'], 404);
        }

        if ($schedule->status !== 'active') {
            return response()->json([
                'message' => 'Критический путь можно рассчитать только для активного графика'
            ], 422);
        }

        try {
            $result = $this->criticalPathService->calculateCriticalPath($schedule);

            return response()->json([
                'message' => 'Критический путь рассчитан',
                'data' => $result
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Ошибка при расчете критического пути',
                'error' => $e->getMessage()
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
            $request->user()->organization_id
        );

        if (!$schedule) {
            return response()->json(['message' => 'График не найден'], 404);
        }

        if ($schedule->status !== 'active') {
            return response()->json([
                'message' => 'Базовый план можно сохранить только для активного графика'
            ], 422);
        }

        $success = $this->scheduleRepository->saveBaseline($id, $request->user()->id);

        if (!$success) {
            return response()->json(['message' => 'Не удалось сохранить базовый план'], 500);
        }

        return response()->json([
            'message' => 'Базовый план сохранен',
            'data' => new ProjectScheduleResource($schedule->fresh(['project', 'createdBy', 'baselineSavedBy']))
        ]);
    }

    /**
     * Очистить базовый план графика
     */
    public function clearBaseline(int $id, Request $request): JsonResponse
    {
        $schedule = $this->scheduleRepository->findForOrganization(
            $id,
            $request->user()->organization_id
        );

        if (!$schedule) {
            return response()->json(['message' => 'График не найден'], 404);
        }

        $success = $this->scheduleRepository->clearBaseline($id);

        if (!$success) {
            return response()->json(['message' => 'Не удалось очистить базовый план'], 500);
        }

        return response()->json([
            'message' => 'Базовый план очищен',
            'data' => new ProjectScheduleResource($schedule->fresh(['project', 'createdBy']))
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
            $schedule = $this->scheduleRepository->createFromTemplate(
                $request->template_id,
                $request->project_id,
                [
                    'name' => $request->name,
                    'planned_start_date' => $request->planned_start_date,
                    'planned_end_date' => $request->planned_end_date,
                    'organization_id' => $request->user()->organization_id,
                    'created_by_user_id' => $request->user()->id,
                ]
            );

            return response()->json([
                'message' => 'График создан из шаблона',
                'data' => new ProjectScheduleResource($schedule->load(['project', 'createdBy']))
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Ошибка при создании графика из шаблона',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить шаблоны графиков
     */
    public function templates(Request $request): JsonResponse
    {
        $templates = $this->scheduleRepository->getTemplatesForOrganization(
            $request->user()->organization_id
        );

        return response()->json([
            'data' => ProjectScheduleResource::collection($templates)
        ]);
    }

    /**
     * Получить статистику по графикам организации
     */
    public function statistics(Request $request): JsonResponse
    {
        $stats = $this->scheduleRepository->getOrganizationStats(
            $request->user()->organization_id
        );

        return response()->json(['data' => $stats]);
    }

    /**
     * Получить графики с просроченными задачами
     */
    public function overdue(Request $request): JsonResponse
    {
        $schedules = $this->scheduleRepository->getWithOverdueTasks(
            $request->user()->organization_id
        );

        return response()->json([
            'data' => ProjectScheduleResource::collection($schedules)
        ]);
    }

    /**
     * Получить недавно обновленные графики
     */
    public function recent(Request $request): JsonResponse
    {
        $limit = min($request->get('limit', 10), 50);
        
        $schedules = $this->scheduleRepository->getRecentlyUpdated(
            $request->user()->organization_id,
            $limit
        );

        return response()->json([
            'data' => ProjectScheduleResource::collection($schedules)
        ]);
    }
} 