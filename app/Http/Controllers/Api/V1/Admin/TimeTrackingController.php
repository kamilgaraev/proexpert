<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\TimeTracking\StoreTimeEntryRequest;
use App\Http\Requests\Api\V1\Admin\TimeTracking\UpdateTimeEntryRequest;
use App\Http\Requests\Api\V1\Admin\TimeTracking\ApproveTimeEntryRequest;
use App\Http\Resources\TimeEntryResource;
use App\Services\TimeTrackingService;
use App\Models\TimeEntry;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
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
     * Получить список записей времени
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $organizationId = Auth::user()->current_organization_id;
            
            $timeEntries = $this->timeTrackingService->getTimeEntries(
                organizationId: $organizationId,
                userId: $request->query('user_id'),
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
            Log::error('[TimeTrackingController@index] Error fetching time entries', [
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
            $timeEntry = $this->timeTrackingService->createTimeEntry($request->validated());

            return response()->json([
                'success' => true,
                'message' => 'Запись времени успешно создана',
                'data' => new TimeEntryResource($timeEntry)
            ], 201);
        } catch (Exception $e) {
            Log::error('[TimeTrackingController@store] Error creating time entry', [
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
            $timeEntry = $this->timeTrackingService->updateTimeEntry($id, $request->validated());

            if (!$timeEntry) {
                return response()->json([
                    'success' => false,
                    'message' => 'Запись времени не найдена или не может быть изменена'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Запись времени успешно обновлена',
                'data' => new TimeEntryResource($timeEntry)
            ]);
        } catch (Exception $e) {
            Log::error('[TimeTrackingController@update] Error updating time entry', [
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
            $deleted = $this->timeTrackingService->deleteTimeEntry($id);

            if (!$deleted) {
                return response()->json([
                    'success' => false,
                    'message' => 'Запись времени не найдена или не может быть удалена'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Запись времени успешно удалена'
            ]);
        } catch (Exception $e) {
            Log::error('[TimeTrackingController@destroy] Error deleting time entry', [
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
     * Утвердить запись времени
     */
    public function approve(ApproveTimeEntryRequest $request, int $id): JsonResponse
    {
        try {
            $approved = $this->timeTrackingService->approveTimeEntry($id, Auth::user());

            if (!$approved) {
                return response()->json([
                    'success' => false,
                    'message' => 'Запись времени не найдена или не может быть утверждена'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Запись времени успешно утверждена'
            ]);
        } catch (Exception $e) {
            Log::error('[TimeTrackingController@approve] Error approving time entry', [
                'error' => $e->getMessage(),
                'id' => $id,
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при утверждении записи времени'
            ], 500);
        }
    }

    /**
     * Отклонить запись времени
     */
    public function reject(ApproveTimeEntryRequest $request, int $id): JsonResponse
    {
        try {
            $rejected = $this->timeTrackingService->rejectTimeEntry(
                $id, 
                Auth::user(), 
                $request->validated()['reason']
            );

            if (!$rejected) {
                return response()->json([
                    'success' => false,
                    'message' => 'Запись времени не найдена или не может быть отклонена'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Запись времени отклонена'
            ]);
        } catch (Exception $e) {
            Log::error('[TimeTrackingController@reject] Error rejecting time entry', [
                'error' => $e->getMessage(),
                'id' => $id,
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при отклонении записи времени'
            ], 500);
        }
    }

    /**
     * Отправить запись на утверждение
     */
    public function submit(int $id): JsonResponse
    {
        try {
            $submitted = $this->timeTrackingService->submitTimeEntry($id);

            if (!$submitted) {
                return response()->json([
                    'success' => false,
                    'message' => 'Запись времени не найдена или не может быть отправлена на утверждение'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Запись времени отправлена на утверждение'
            ]);
        } catch (Exception $e) {
            Log::error('[TimeTrackingController@submit] Error submitting time entry', [
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
     * Получить статистику по времени
     */
    public function statistics(Request $request): JsonResponse
    {
        try {
            $organizationId = Auth::user()->current_organization_id;
            
            $statistics = $this->timeTrackingService->getTimeStatistics(
                organizationId: $organizationId,
                userId: $request->query('user_id'),
                projectId: $request->query('project_id'),
                startDate: $request->query('start_date'),
                endDate: $request->query('end_date')
            );

            return response()->json([
                'success' => true,
                'data' => $statistics
            ]);
        } catch (Exception $e) {
            Log::error('[TimeTrackingController@statistics] Error fetching statistics', [
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
            $startDate = $request->query('start_date', now()->startOfMonth()->format('Y-m-d'));
            $endDate = $request->query('end_date', now()->endOfMonth()->format('Y-m-d'));
            
            $timeEntriesByDays = $this->timeTrackingService->getTimeEntriesByDays(
                organizationId: $organizationId,
                userId: $request->query('user_id'),
                projectId: $request->query('project_id'),
                startDate: $startDate,
                endDate: $endDate
            );

            return response()->json([
                'success' => true,
                'data' => $timeEntriesByDays
            ]);
        } catch (Exception $e) {
            Log::error('[TimeTrackingController@calendar] Error fetching calendar data', [
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
     * Получить отчет по времени
     */
    public function report(Request $request): JsonResponse
    {
        try {
            $organizationId = Auth::user()->current_organization_id;
            $startDate = $request->query('start_date', now()->startOfMonth()->format('Y-m-d'));
            $endDate = $request->query('end_date', now()->endOfMonth()->format('Y-m-d'));
            $groupBy = $request->query('group_by', 'user');
            
            $report = $this->timeTrackingService->getTimeReport(
                organizationId: $organizationId,
                userId: $request->query('user_id'),
                projectId: $request->query('project_id'),
                startDate: $startDate,
                endDate: $endDate,
                groupBy: $groupBy
            );

            return response()->json([
                'success' => true,
                'data' => $report
            ]);
        } catch (Exception $e) {
            Log::error('[TimeTrackingController@report] Error generating report', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'organization_id' => Auth::user()->current_organization_id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при генерации отчета'
            ], 500);
        }
    }
}