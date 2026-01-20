<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\TimeTracking\StoreTimeEntryRequest;
use App\Http\Requests\Api\V1\Admin\TimeTracking\UpdateTimeEntryRequest;
use App\Http\Requests\Api\V1\Admin\TimeTracking\ApproveTimeEntryRequest;
use App\Http\Resources\TimeEntryResource;
use App\Http\Responses\AdminResponse;
use App\Services\TimeTrackingService;
use App\Models\TimeEntry;
use App\Exceptions\BusinessLogicException;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

use function trans_message;

/**
 * Контроллер учёта рабочего времени
 * 
 * Thin Controller - вся логика в TimeTrackingService
 */
class TimeTrackingController extends Controller
{
    public function __construct(
        protected TimeTrackingService $timeTrackingService
    ) {
    }

    /**
     * Получить список записей времени с фильтрацией
     * 
     * GET /api/v1/admin/time-tracking
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->getCurrentOrganizationId($request);
            
            $timeEntries = $this->timeTrackingService->getTimeEntries(
                organizationId: $organizationId,
                userId: $request->query('user_id') ? (int)$request->query('user_id') : null,
                projectId: $request->query('project_id') ? (int)$request->query('project_id') : null,
                status: $request->query('status'),
                startDate: $request->query('start_date'),
                endDate: $request->query('end_date'),
                billable: $request->query('billable') !== null ? (bool)$request->query('billable') : null,
                perPage: (int)$request->query('per_page', 15)
            );

            return AdminResponse::success(
                TimeEntryResource::collection($timeEntries->items()),
                null,
                200,
                [
                    'current_page' => $timeEntries->currentPage(),
                    'last_page' => $timeEntries->lastPage(),
                    'per_page' => $timeEntries->perPage(),
                    'total' => $timeEntries->total(),
                ]
            );
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode());
        } catch (\Throwable $e) {
            Log::error('[TimeTrackingController] Error fetching time entries', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()->id,
            ]);
            return AdminResponse::error(trans_message('time_tracking.fetch_failed'), 500);
        }
    }

    /**
     * Создать новую запись времени
     * 
     * POST /api/v1/admin/time-tracking
     */
    public function store(StoreTimeEntryRequest $request): JsonResponse
    {
        try {
            $timeEntry = $this->timeTrackingService->createTimeEntry($request->validated());

            return AdminResponse::success(
                new TimeEntryResource($timeEntry),
                trans_message('time_tracking.entry_created'),
                201
            );
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode());
        } catch (\Throwable $e) {
            Log::error('[TimeTrackingController] Error creating time entry', [
                'error' => $e->getMessage(),
                'data' => $request->validated(),
            ]);
            return AdminResponse::error(trans_message('time_tracking.create_failed'), 500);
        }
    }

    /**
     * Получить конкретную запись времени
     * 
     * GET /api/v1/admin/time-tracking/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $this->getCurrentOrganizationId($request);
            
            $timeEntry = TimeEntry::with(['user', 'project', 'workType', 'task', 'approvedBy'])
                ->where('organization_id', $organizationId)
                ->findOrFail($id);

            return AdminResponse::success(new TimeEntryResource($timeEntry));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return AdminResponse::error(trans_message('time_tracking.entry_not_found'), 404);
        } catch (\Throwable $e) {
            return AdminResponse::error(trans_message('time_tracking.fetch_failed'), 500);
        }
    }

    /**
     * Обновить запись времени
     * 
     * PUT/PATCH /api/v1/admin/time-tracking/{id}
     */
    public function update(UpdateTimeEntryRequest $request, int $id): JsonResponse
    {
        try {
            $organizationId = $this->getCurrentOrganizationId($request);
            
            $timeEntry = TimeEntry::where('organization_id', $organizationId)
                ->findOrFail($id);

            $updatedEntry = $this->timeTrackingService->updateTimeEntry($timeEntry, $request->validated());

            return AdminResponse::success(
                new TimeEntryResource($updatedEntry),
                trans_message('time_tracking.entry_updated')
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return AdminResponse::error(trans_message('time_tracking.entry_not_found'), 404);
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode());
        } catch (\Throwable $e) {
            Log::error('[TimeTrackingController] Error updating time entry', [
                'error' => $e->getMessage(),
                'id' => $id,
            ]);
            return AdminResponse::error(trans_message('time_tracking.update_failed'), 500);
        }
    }

    /**
     * Удалить запись времени
     * 
     * DELETE /api/v1/admin/time-tracking/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $this->getCurrentOrganizationId($request);
            
            $timeEntry = TimeEntry::where('organization_id', $organizationId)
                ->findOrFail($id);

            $this->timeTrackingService->deleteTimeEntry($timeEntry);

            return AdminResponse::success(
                null,
                trans_message('time_tracking.entry_deleted')
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return AdminResponse::error(trans_message('time_tracking.entry_not_found'), 404);
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode());
        } catch (\Throwable $e) {
            Log::error('[TimeTrackingController] Error deleting time entry', [
                'error' => $e->getMessage(),
                'id' => $id,
            ]);
            return AdminResponse::error(trans_message('time_tracking.delete_failed'), 500);
        }
    }

    /**
     * Утвердить запись времени
     * 
     * POST /api/v1/admin/time-tracking/{id}/approve
     */
    public function approve(ApproveTimeEntryRequest $request, int $id): JsonResponse
    {
        try {
            $organizationId = $this->getCurrentOrganizationId($request);
            
            $timeEntry = TimeEntry::where('organization_id', $organizationId)
                ->findOrFail($id);

            $approvedEntry = $this->timeTrackingService->approveTimeEntry(
                $timeEntry,
                $request->user()->id,
                $request->input('notes')
            );

            return AdminResponse::success(
                new TimeEntryResource($approvedEntry),
                trans_message('time_tracking.entry_approved')
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return AdminResponse::error(trans_message('time_tracking.entry_not_found'), 404);
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode());
        } catch (\Throwable $e) {
            Log::error('[TimeTrackingController] Error approving time entry', [
                'error' => $e->getMessage(),
                'id' => $id,
            ]);
            return AdminResponse::error(trans_message('time_tracking.approve_failed'), 500);
        }
    }

    /**
     * Отклонить запись времени
     * 
     * POST /api/v1/admin/time-tracking/{id}/reject
     */
    public function reject(ApproveTimeEntryRequest $request, int $id): JsonResponse
    {
        try {
            $organizationId = $this->getCurrentOrganizationId($request);
            
            $timeEntry = TimeEntry::where('organization_id', $organizationId)
                ->findOrFail($id);

            $rejectedEntry = $this->timeTrackingService->rejectTimeEntry(
                $timeEntry,
                $request->user()->id,
                $request->input('notes')
            );

            return AdminResponse::success(
                new TimeEntryResource($rejectedEntry),
                trans_message('time_tracking.entry_rejected')
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return AdminResponse::error(trans_message('time_tracking.entry_not_found'), 404);
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode());
        } catch (\Throwable $e) {
            Log::error('[TimeTrackingController] Error rejecting time entry', [
                'error' => $e->getMessage(),
                'id' => $id,
            ]);
            return AdminResponse::error(trans_message('time_tracking.approve_failed'), 500);
        }
    }

    /**
     * Отправить запись времени на утверждение
     * 
     * POST /api/v1/admin/time-tracking/{id}/submit
     */
    public function submit(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $this->getCurrentOrganizationId($request);
            
            $timeEntry = TimeEntry::where('organization_id', $organizationId)
                ->where('user_id', $request->user()->id)
                ->findOrFail($id);

            $submittedEntry = $this->timeTrackingService->submitTimeEntry($timeEntry);

            return AdminResponse::success(
                new TimeEntryResource($submittedEntry),
                trans_message('time_tracking.entry_updated')
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return AdminResponse::error(trans_message('time_tracking.entry_not_found'), 404);
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode());
        } catch (\Throwable $e) {
            return AdminResponse::error(trans_message('time_tracking.update_failed'), 500);
        }
    }

    /**
     * Получить статистику по времени
     * 
     * GET /api/v1/admin/time-tracking/statistics
     */
    public function statistics(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->getCurrentOrganizationId($request);
            
            $stats = $this->timeTrackingService->getStatistics(
                organizationId: $organizationId,
                userId: $request->query('user_id') ? (int)$request->query('user_id') : null,
                projectId: $request->query('project_id') ? (int)$request->query('project_id') : null,
                startDate: $request->query('start_date'),
                endDate: $request->query('end_date')
            );

            return AdminResponse::success($stats, trans_message('time_tracking.stats_loaded'));
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode());
        } catch (\Throwable $e) {
            Log::error('[TimeTrackingController] Error fetching statistics', [
                'error' => $e->getMessage(),
            ]);
            return AdminResponse::error(trans_message('time_tracking.stats_failed'), 500);
        }
    }

    /**
     * Получить данные для календаря
     * 
     * GET /api/v1/admin/time-tracking/calendar
     */
    public function calendar(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->getCurrentOrganizationId($request);
            
            $calendarData = $this->timeTrackingService->getCalendarData(
                organizationId: $organizationId,
                userId: $request->query('user_id') ? (int)$request->query('user_id') : null,
                month: $request->query('month'),
                year: $request->query('year') ? (int)$request->query('year') : null
            );

            return AdminResponse::success($calendarData);
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode());
        } catch (\Throwable $e) {
            return AdminResponse::error(trans_message('time_tracking.fetch_failed'), 500);
        }
    }

    /**
     * Получить отчёт по времени
     * 
     * GET /api/v1/admin/time-tracking/report
     */
    public function report(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->getCurrentOrganizationId($request);
            
            $report = $this->timeTrackingService->generateReport(
                organizationId: $organizationId,
                userId: $request->query('user_id') ? (int)$request->query('user_id') : null,
                projectId: $request->query('project_id') ? (int)$request->query('project_id') : null,
                startDate: $request->query('start_date'),
                endDate: $request->query('end_date'),
                groupBy: $request->query('group_by', 'user')
            );

            return AdminResponse::success($report, trans_message('time_tracking.summary_loaded'));
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode());
        } catch (\Throwable $e) {
            Log::error('[TimeTrackingController] Error generating report', [
                'error' => $e->getMessage(),
            ]);
            return AdminResponse::error(trans_message('reports.generation_failed'), 500);
        }
    }

    /**
     * Получить ID текущей организации
     */
    protected function getCurrentOrganizationId(Request $request): int
    {
        $organizationId = $request->user()->current_organization_id;

        if (!$organizationId) {
            throw new BusinessLogicException(
                trans_message('errors.organization_not_found'),
                400
            );
        }

        return $organizationId;
    }
}
