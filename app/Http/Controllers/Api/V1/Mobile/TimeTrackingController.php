<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Mobile\TimeTracking\StoreTimeEntryRequest;
use App\Http\Requests\Api\V1\Mobile\TimeTracking\UpdateTimeEntryRequest;
use App\Http\Resources\TimeEntryResource;
use App\Services\TimeTrackingService;
use App\Models\TimeEntry;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Exception;

class TimeTrackingController extends Controller
{
    protected TimeTrackingService $timeTrackingService;

    public function __construct(TimeTrackingService $timeTrackingService)
    {
        $this->timeTrackingService = $timeTrackingService;
    }

    /**
     * Получить список записей времени текущего пользователя
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $organizationId = Auth::user()->current_organization_id;
            $userId = Auth::id();
            
            $timeEntries = $this->timeTrackingService->getTimeEntries(
                organizationId: $organizationId,
                userId: $userId,
                projectId: $request->query('project_id'),
                status: $request->query('status'),
                startDate: $request->query('start_date'),
                endDate: $request->query('end_date'),
                billable: $request->query('billable') !== null ? (bool)$request->query('billable') : null,
                perPage: (int)$request->query('per_page', 15)
            );

            return response()->json([
                'success' => true,
                'data' => TimeEntryResource::collection($timeEntries->items()),
                'meta' => [
                    'current_page' => $timeEntries->currentPage(),
                    'last_page' => $timeEntries->lastPage(),
                    'per_page' => $timeEntries->perPage(),
                    'total' => $timeEntries->total(),
                ]
            ]);
        } catch (Exception $e) {
            Log::error('[Mobile\TimeTrackingController@index] Error fetching time entries', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'organization_id' => Auth::user()->current_organization_id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении записей времени'
            ], 500);
        }
    }

    /**
     * Создать новую запись времени
     */
    public function store(StoreTimeEntryRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            $data['user_id'] = Auth::id();
            $data['organization_id'] = Auth::user()->current_organization_id;

            $timeEntry = $this->timeTrackingService->createTimeEntry($data);

            return response()->json([
                'success' => true,
                'message' => 'Запись времени успешно создана',
                'data' => new TimeEntryResource($timeEntry)
            ], 201);
        } catch (Exception $e) {
            Log::error('[Mobile\TimeTrackingController@store] Error creating time entry', [
                'error' => $e->getMessage(),
                'data' => $request->validated(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Получить конкретную запись времени
     */
    public function show(int $id): JsonResponse
    {
        try {
            $timeEntry = TimeEntry::with(['user', 'project', 'workType', 'task', 'approvedBy'])
                ->where('organization_id', Auth::user()->current_organization_id)
                ->where('user_id', Auth::id())
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => new TimeEntryResource($timeEntry)
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Запись времени не найдена'
            ], 404);
        }
    }

    /**
     * Обновить запись времени
     */
    public function update(UpdateTimeEntryRequest $request, int $id): JsonResponse
    {
        try {
            // Проверяем, что пользователь может редактировать только свои записи
            $timeEntry = TimeEntry::where('organization_id', Auth::user()->current_organization_id)
                ->where('user_id', Auth::id())
                ->findOrFail($id);

            // Проверяем, что запись можно редактировать (не утверждена)
            if ($timeEntry->status === 'approved') {
                return response()->json([
                    'success' => false,
                    'message' => 'Нельзя редактировать утвержденную запись времени'
                ], 403);
            }

            $updatedTimeEntry = $this->timeTrackingService->updateTimeEntry($id, $request->validated());

            return response()->json([
                'success' => true,
                'message' => 'Запись времени успешно обновлена',
                'data' => new TimeEntryResource($updatedTimeEntry)
            ]);
        } catch (Exception $e) {
            Log::error('[Mobile\TimeTrackingController@update] Error updating time entry', [
                'error' => $e->getMessage(),
                'id' => $id,
                'data' => $request->validated(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Удалить запись времени
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            // Проверяем, что пользователь может удалять только свои записи
            $timeEntry = TimeEntry::where('organization_id', Auth::user()->current_organization_id)
                ->where('user_id', Auth::id())
                ->findOrFail($id);

            // Проверяем, что запись можно удалить (не утверждена)
            if ($timeEntry->status === 'approved') {
                return response()->json([
                    'success' => false,
                    'message' => 'Нельзя удалить утвержденную запись времени'
                ], 403);
            }

            $deleted = $this->timeTrackingService->deleteTimeEntry($id);

            return response()->json([
                'success' => true,
                'message' => 'Запись времени успешно удалена'
            ]);
        } catch (Exception $e) {
            Log::error('[Mobile\TimeTrackingController@destroy] Error deleting time entry', [
                'error' => $e->getMessage(),
                'id' => $id,
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при удалении записи времени'
            ], 500);
        }
    }

    /**
     * Отправить запись на утверждение
     */
    public function submit(int $id): JsonResponse
    {
        try {
            // Проверяем, что пользователь может отправлять только свои записи
            $timeEntry = TimeEntry::where('organization_id', Auth::user()->current_organization_id)
                ->where('user_id', Auth::id())
                ->findOrFail($id);

            $submitted = $this->timeTrackingService->submitTimeEntry($id);

            if (!$submitted) {
                return response()->json([
                    'success' => false,
                    'message' => 'Запись времени не может быть отправлена на утверждение'
                ], 400);
            }

            return response()->json([
                'success' => true,
                'message' => 'Запись времени отправлена на утверждение'
            ]);
        } catch (Exception $e) {
            Log::error('[Mobile\TimeTrackingController@submit] Error submitting time entry', [
                'error' => $e->getMessage(),
                'id' => $id,
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при отправке записи времени на утверждение'
            ], 500);
        }
    }

    /**
     * Получить статистику по времени для текущего пользователя
     */
    public function statistics(Request $request): JsonResponse
    {
        try {
            $organizationId = Auth::user()->current_organization_id;
            $userId = Auth::id();
            
            $statistics = $this->timeTrackingService->getTimeStatistics(
                organizationId: $organizationId,
                userId: $userId,
                projectId: $request->query('project_id'),
                startDate: $request->query('start_date'),
                endDate: $request->query('end_date')
            );

            return response()->json([
                'success' => true,
                'data' => $statistics
            ]);
        } catch (Exception $e) {
            Log::error('[Mobile\TimeTrackingController@statistics] Error fetching statistics', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'organization_id' => Auth::user()->current_organization_id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении статистики'
            ], 500);
        }
    }

    /**
     * Получить записи времени по дням для календаря
     */
    public function calendar(Request $request): JsonResponse
    {
        try {
            $organizationId = Auth::user()->current_organization_id;
            $userId = Auth::id();
            $startDate = $request->query('start_date', now()->startOfMonth()->format('Y-m-d'));
            $endDate = $request->query('end_date', now()->endOfMonth()->format('Y-m-d'));
            
            $timeEntriesByDays = $this->timeTrackingService->getTimeEntriesByDays(
                organizationId: $organizationId,
                userId: $userId,
                projectId: $request->query('project_id'),
                startDate: $startDate,
                endDate: $endDate
            );

            return response()->json([
                'success' => true,
                'data' => $timeEntriesByDays
            ]);
        } catch (Exception $e) {
            Log::error('[Mobile\TimeTrackingController@calendar] Error fetching calendar data', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'organization_id' => Auth::user()->current_organization_id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении данных календаря'
            ], 500);
        }
    }

    /**
     * Начать отслеживание времени (создать запись с текущим временем)
     */
    public function startTimer(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'project_id' => 'required|exists:projects,id',
                'work_type_id' => 'nullable|exists:work_types,id',
                'task_id' => 'nullable|exists:schedule_tasks,id',
                'title' => 'required|string|max:255',
                'description' => 'nullable|string',
                'location' => 'nullable|string|max:255',
            ]);

            $data = $request->only(['project_id', 'work_type_id', 'task_id', 'title', 'description', 'location']);
            $data['user_id'] = Auth::id();
            $data['organization_id'] = Auth::user()->current_organization_id;
            $data['work_date'] = now()->format('Y-m-d');
            $data['start_time'] = now()->format('H:i:s');
            $data['status'] = 'draft';

            $timeEntry = $this->timeTrackingService->createTimeEntry($data);

            return response()->json([
                'success' => true,
                'message' => 'Отслеживание времени начато',
                'data' => new TimeEntryResource($timeEntry)
            ], 201);
        } catch (Exception $e) {
            Log::error('[Mobile\TimeTrackingController@startTimer] Error starting timer', [
                'error' => $e->getMessage(),
                'data' => $request->all(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при начале отслеживания времени'
            ], 500);
        }
    }

    /**
     * Остановить отслеживание времени
     */
    public function stopTimer(int $id): JsonResponse
    {
        try {
            $timeEntry = TimeEntry::where('organization_id', Auth::user()->current_organization_id)
                ->where('user_id', Auth::id())
                ->where('status', 'draft')
                ->whereNull('end_time')
                ->findOrFail($id);

            $updatedTimeEntry = $this->timeTrackingService->updateTimeEntry($id, [
                'end_time' => now()->format('H:i:s')
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Отслеживание времени остановлено',
                'data' => new TimeEntryResource($updatedTimeEntry)
            ]);
        } catch (Exception $e) {
            Log::error('[Mobile\TimeTrackingController@stopTimer] Error stopping timer', [
                'error' => $e->getMessage(),
                'id' => $id,
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при остановке отслеживания времени'
            ], 500);
        }
    }

    /**
     * Получить активный таймер пользователя
     */
    public function activeTimer(): JsonResponse
    {
        try {
            $activeTimer = TimeEntry::where('organization_id', Auth::user()->current_organization_id)
                ->where('user_id', Auth::id())
                ->where('status', 'draft')
                ->whereNull('end_time')
                ->with(['project', 'workType', 'task'])
                ->first();

            return response()->json([
                'success' => true,
                'data' => $activeTimer ? new TimeEntryResource($activeTimer) : null
            ]);
        } catch (Exception $e) {
            Log::error('[Mobile\TimeTrackingController@activeTimer] Error fetching active timer', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении активного таймера'
            ], 500);
        }
    }
}