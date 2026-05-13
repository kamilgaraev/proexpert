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
use App\Services\Report\ReportService;
use App\Models\TimeEntry;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

use function trans_message;

class TimeTrackingController extends Controller
{
    public function __construct(
        protected TimeTrackingService $timeTrackingService,
        protected ReportService $reportService
    ) {
    }

    public function export(Request $request): StreamedResponse | JsonResponse
    {
        $organizationId = $this->getCurrentOrganizationId($request);
        $this->validatedProjectId($request, $organizationId);

        try {
            $result = $this->reportService->getTimeTrackingReport($request);

            if ($result instanceof StreamedResponse) {
                return $result;
            }

            return AdminResponse::success($result);
        } catch (\Throwable $e) {
            Log::error('[TimeTrackingController] Ошибка экспорта: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return AdminResponse::error(trans_message('time_tracking.export_failed'), 500);
        }
    }

    public function workers(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->getCurrentOrganizationId($request);
            $workers = $this->timeTrackingService->getWorkers($organizationId);
            return AdminResponse::success($workers);
        } catch (\Throwable $e) {
            Log::error('[TimeTrackingController] Ошибка получения списка работников: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return AdminResponse::error(trans_message('time_tracking.workers_failed'), 500);
        }
    }

    public function index(Request $request): JsonResponse
    {
        $organizationId = $this->getCurrentOrganizationId($request);
        $projectId = $this->validatedProjectId($request, $organizationId);

        try {
            $timeEntries = $this->timeTrackingService->getTimeEntries(
                organizationId: $organizationId,
                userId: $request->query('user_id') ? (int)$request->query('user_id') : null,
                projectId: $projectId,
                status: $request->query('status'),
                startDate: $request->query('start_date'),
                endDate: $request->query('end_date'),
                billable: $request->query('billable') !== null ? filter_var($request->query('billable'), FILTER_VALIDATE_BOOLEAN) : null,
                perPage: min((int)$request->query('per_page', 15), 100),
                workerType: $request->query('worker_type'),
                workerName: $request->query('worker_name')
            );

            return AdminResponse::success([
                'items' => TimeEntryResource::collection($timeEntries->items()),
                'pagination' => [
                    'current_page' => $timeEntries->currentPage(),
                    'last_page' => $timeEntries->lastPage(),
                    'per_page' => $timeEntries->perPage(),
                    'total' => $timeEntries->total(),
                ]
            ]);
        } catch (\Throwable $e) {
            Log::error('[TimeTrackingController] Ошибка получения записей времени: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return AdminResponse::error(trans_message('time_tracking.fetch_failed'), 500);
        }
    }

    public function store(StoreTimeEntryRequest $request): JsonResponse
    {
        try {
            $timeEntry = $this->timeTrackingService->createTimeEntry($request->validated());

            return AdminResponse::success(
                new TimeEntryResource($timeEntry),
                trans_message('time_tracking.entry_created'),
                201
            );
        } catch (\Throwable $e) {
            Log::error('[TimeTrackingController] Ошибка создания записи времени', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data' => $request->validated(),
            ]);
            return AdminResponse::error(trans_message('time_tracking.create_failed'), 500);
        }
    }

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
            Log::error('[TimeTrackingController] Ошибка получения записи времени', [
                'error' => $e->getMessage(),
                'id' => $id,
            ]);
            return AdminResponse::error(trans_message('time_tracking.fetch_failed'), 500);
        }
    }

    public function update(UpdateTimeEntryRequest $request, int $id): JsonResponse
    {
        try {
            $organizationId = $this->getCurrentOrganizationId($request);
            
            $timeEntry = TimeEntry::where('organization_id', $organizationId)
                ->findOrFail($id);

            if (!$timeEntry->canBeEdited()) {
                return AdminResponse::error(trans_message('time_tracking.cannot_edit_approved'), 400);
            }

            $updatedEntry = $this->timeTrackingService->updateTimeEntry($id, $request->validated());

            if (!$updatedEntry) {
                return AdminResponse::error(trans_message('time_tracking.update_failed'), 400);
            }

            return AdminResponse::success(
                new TimeEntryResource($updatedEntry),
                trans_message('time_tracking.entry_updated')
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return AdminResponse::error(trans_message('time_tracking.entry_not_found'), 404);
        } catch (\Throwable $e) {
            Log::error('[TimeTrackingController] Ошибка обновления записи времени', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'id' => $id,
            ]);
            return AdminResponse::error(trans_message('time_tracking.update_failed'), 500);
        }
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $this->getCurrentOrganizationId($request);
            
            $timeEntry = TimeEntry::where('organization_id', $organizationId)
                ->findOrFail($id);

            if (!$timeEntry->canBeEdited()) {
                return AdminResponse::error(trans_message('time_tracking.cannot_edit_approved'), 400);
            }

            $deleted = $this->timeTrackingService->deleteTimeEntry($id);

            if (!$deleted) {
                return AdminResponse::error(trans_message('time_tracking.delete_failed'), 400);
            }

            return AdminResponse::success(
                null,
                trans_message('time_tracking.entry_deleted')
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return AdminResponse::error(trans_message('time_tracking.entry_not_found'), 404);
        } catch (\Throwable $e) {
            Log::error('[TimeTrackingController] Ошибка удаления записи времени', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'id' => $id,
            ]);
            return AdminResponse::error(trans_message('time_tracking.delete_failed'), 500);
        }
    }

    public function approve(ApproveTimeEntryRequest $request, int $id): JsonResponse
    {
        try {
            $organizationId = $this->getCurrentOrganizationId($request);
            
            $timeEntry = TimeEntry::where('organization_id', $organizationId)
                ->findOrFail($id);

            if (!$timeEntry->canBeApproved()) {
                return AdminResponse::error(trans_message('time_tracking.already_approved'), 400);
            }

            $approved = $this->timeTrackingService->approveTimeEntry($id, $request->user());

            if (!$approved) {
                return AdminResponse::error(trans_message('time_tracking.approve_failed'), 400);
            }

            $timeEntry->refresh();

            return AdminResponse::success(
                new TimeEntryResource($timeEntry->load(['user', 'project', 'workType', 'task', 'approvedBy'])),
                trans_message('time_tracking.entry_approved')
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return AdminResponse::error(trans_message('time_tracking.entry_not_found'), 404);
        } catch (\Throwable $e) {
            Log::error('[TimeTrackingController] Ошибка утверждения записи времени', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'id' => $id,
            ]);
            return AdminResponse::error(trans_message('time_tracking.approve_failed'), 500);
        }
    }

    public function reject(ApproveTimeEntryRequest $request, int $id): JsonResponse
    {
        try {
            $organizationId = $this->getCurrentOrganizationId($request);
            
            $timeEntry = TimeEntry::where('organization_id', $organizationId)
                ->findOrFail($id);

            if (!$timeEntry->canBeApproved()) {
                return AdminResponse::error(trans_message('time_tracking.already_approved'), 400);
            }

            $reason = $request->input('notes') ?? $request->input('reason') ?? '';

            if (empty($reason)) {
                return AdminResponse::error(trans_message('time_tracking.rejection_reason_required'), 400);
            }

            $rejected = $this->timeTrackingService->rejectTimeEntry($id, $request->user(), $reason);

            if (!$rejected) {
                return AdminResponse::error(trans_message('time_tracking.approve_failed'), 400);
            }

            $timeEntry->refresh();

            return AdminResponse::success(
                new TimeEntryResource($timeEntry->load(['user', 'project', 'workType', 'task', 'approvedBy'])),
                trans_message('time_tracking.entry_rejected')
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return AdminResponse::error(trans_message('time_tracking.entry_not_found'), 404);
        } catch (\Throwable $e) {
            Log::error('[TimeTrackingController] Ошибка отклонения записи времени', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'id' => $id,
            ]);
            return AdminResponse::error(trans_message('time_tracking.approve_failed'), 500);
        }
    }

    public function submit(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $this->getCurrentOrganizationId($request);
            
            $timeEntry = TimeEntry::where('organization_id', $organizationId)
                ->findOrFail($id);

            if (!$timeEntry->canBeEdited()) {
                return AdminResponse::error(trans_message('time_tracking.cannot_edit_approved'), 400);
            }

            $submitted = $this->timeTrackingService->submitTimeEntry($id);

            if (!$submitted) {
                return AdminResponse::error(trans_message('time_tracking.update_failed'), 400);
            }

            $timeEntry->refresh();

            return AdminResponse::success(
                new TimeEntryResource($timeEntry),
                trans_message('time_tracking.entry_updated')
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return AdminResponse::error(trans_message('time_tracking.entry_not_found'), 404);
        } catch (\Throwable $e) {
            Log::error('[TimeTrackingController] Ошибка отправки на утверждение', [
                'error' => $e->getMessage(),
                'id' => $id,
            ]);
            return AdminResponse::error(trans_message('time_tracking.update_failed'), 500);
        }
    }

    public function statistics(Request $request): JsonResponse
    {
        $organizationId = $this->getCurrentOrganizationId($request);
        $projectId = $this->validatedProjectId($request, $organizationId);

        try {
            $stats = $this->timeTrackingService->getTimeStatistics(
                organizationId: $organizationId,
                userId: $request->query('user_id') ? (int)$request->query('user_id') : null,
                projectId: $projectId,
                startDate: $request->query('start_date'),
                endDate: $request->query('end_date')
            );

            return AdminResponse::success($stats, trans_message('time_tracking.stats_loaded'));
        } catch (\Throwable $e) {
            Log::error('[TimeTrackingController] Ошибка получения статистики: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return AdminResponse::error(trans_message('time_tracking.stats_failed'), 500);
        }
    }

    public function calendar(Request $request): JsonResponse
    {
        $organizationId = $this->getCurrentOrganizationId($request);
        $projectId = $this->validatedProjectId($request, $organizationId);

        try {
            $startDate = $request->query('start_date') ?? now()->startOfMonth()->format('Y-m-d');
            $endDate = $request->query('end_date') ?? now()->endOfMonth()->format('Y-m-d');
            
            $calendarData = $this->timeTrackingService->getTimeEntriesByDays(
                organizationId: $organizationId,
                startDate: $startDate,
                endDate: $endDate,
                userId: $request->query('user_id') ? (int)$request->query('user_id') : null,
                projectId: $projectId
            );

            return AdminResponse::success($calendarData);
        } catch (\Throwable $e) {
            Log::error('[TimeTrackingController] Ошибка получения календаря', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return AdminResponse::error(trans_message('time_tracking.fetch_failed'), 500);
        }
    }

    public function report(Request $request): JsonResponse
    {
        $organizationId = $this->getCurrentOrganizationId($request);
        $projectId = $this->validatedProjectId($request, $organizationId);

        try {
            $startDate = $request->query('start_date');
            $endDate = $request->query('end_date');
            
            if (!$startDate || !$endDate) {
                return AdminResponse::error(trans_message('time_tracking.report_period_required'), 400);
            }
            
            $report = $this->timeTrackingService->getTimeReport(
                organizationId: $organizationId,
                startDate: $startDate,
                endDate: $endDate,
                userId: $request->query('user_id') ? (int)$request->query('user_id') : null,
                projectId: $projectId,
                groupBy: $request->query('group_by', 'user')
            );

            return AdminResponse::success($report, trans_message('time_tracking.summary_loaded'));
        } catch (\Throwable $e) {
            Log::error('[TimeTrackingController] Ошибка генерации отчета', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return AdminResponse::error(trans_message('time_tracking.report_failed'), 500);
        }
    }

    protected function getCurrentOrganizationId(Request $request): int
    {
        $organizationId = $request->attributes->get('current_organization_id')
            ?? $request->user()?->current_organization_id;

        if (!$organizationId) {
            throw new \Exception(trans_message('time_tracking.organization_not_found'));
        }

        return (int) $organizationId;
    }

    private function validatedProjectId(Request $request, int $organizationId): ?int
    {
        $validated = $request->validate([
            'project_id' => [
                'nullable',
                'integer',
                Rule::exists('projects', 'id')->where('organization_id', $organizationId),
            ],
        ]);

        return isset($validated['project_id']) ? (int) $validated['project_id'] : null;
    }
}
