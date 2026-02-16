<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomReport;
use App\Models\CustomReportSchedule;
use App\Services\Report\CustomReportSchedulerService;
use App\Http\Requests\Api\V1\Admin\CustomReport\CreateScheduleRequest;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomReportScheduleController extends Controller
{
    public function __construct(
        protected CustomReportSchedulerService $schedulerService
    ) {
    }

    public function index(Request $request, int $reportId): JsonResponse
    {
        $user = $request->user();
        $organizationId = $user->current_organization_id;

        $report = CustomReport::find($reportId);

        if (!$report || !$report->canBeViewedBy($user->id, $organizationId)) {
            return AdminResponse::error(trans_message('reports.custom.not_found'), 404);
        }

        $schedules = $report->schedules()
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->get();

        return AdminResponse::success($schedules);
    }

    public function store(CreateScheduleRequest $request, int $reportId): JsonResponse
    {
        $user = $request->user();
        $organizationId = $user->current_organization_id;

        $report = CustomReport::find($reportId);

        if (!$report || !$report->canBeViewedBy($user->id, $organizationId)) {
            return AdminResponse::error(trans_message('reports.custom.not_found'), 404);
        }

        try {
            $scheduleData = array_merge($request->validated(), [
                'user_id' => $user->id,
            ]);

            $schedule = $this->schedulerService->createSchedule($report, $scheduleData);

            return AdminResponse::success($schedule->load('user'), trans_message('reports.schedule.created'), 201);

        } catch (\Exception $e) {
            return AdminResponse::error($e->getMessage(), 422);
        }
    }

    public function show(Request $request, int $reportId, int $scheduleId): JsonResponse
    {
        $user = $request->user();
        $organizationId = $user->current_organization_id;

        $schedule = CustomReportSchedule::with(['customReport', 'user', 'lastExecution'])
            ->find($scheduleId);

        if (!$schedule || $schedule->organization_id !== $organizationId) {
            return AdminResponse::error(trans_message('reports.schedule.not_found'), 404);
        }

        return AdminResponse::success($schedule);
    }

    public function update(Request $request, int $reportId, int $scheduleId): JsonResponse
    {
        $user = $request->user();
        $organizationId = $user->current_organization_id;

        $schedule = CustomReportSchedule::find($scheduleId);

        if (!$schedule || $schedule->organization_id !== $organizationId) {
            return AdminResponse::error(trans_message('reports.schedule.not_found'), 404);
        }

        $data = $request->validate([
            'schedule_type' => 'sometimes|string|in:daily,weekly,monthly,custom_cron',
            'schedule_config' => 'sometimes|array',
            'filters_preset' => 'nullable|array',
            'recipient_emails' => 'sometimes|array|min:1',
            'recipient_emails.*' => 'email',
            'export_format' => 'sometimes|string|in:csv,excel,pdf',
        ]);

        try {
            $schedule = $this->schedulerService->updateSchedule($schedule, $data);

            return AdminResponse::success($schedule, trans_message('reports.schedule.updated'));

        } catch (\Exception $e) {
            return AdminResponse::error($e->getMessage(), 422);
        }
    }

    public function destroy(Request $request, int $reportId, int $scheduleId): JsonResponse
    {
        $user = $request->user();
        $organizationId = $user->current_organization_id;

        $schedule = CustomReportSchedule::find($scheduleId);

        if (!$schedule || $schedule->organization_id !== $organizationId) {
            return AdminResponse::error(trans_message('reports.schedule.not_found'), 404);
        }

        $this->schedulerService->deleteSchedule($schedule);

        return AdminResponse::success(null, trans_message('reports.schedule.deleted'));
    }

    public function toggle(Request $request, int $reportId, int $scheduleId): JsonResponse
    {
        $user = $request->user();
        $organizationId = $user->current_organization_id;

        $schedule = CustomReportSchedule::find($scheduleId);

        if (!$schedule || $schedule->organization_id !== $organizationId) {
            return AdminResponse::error(trans_message('reports.schedule.not_found'), 404);
        }

        if ($schedule->is_active) {
            $this->schedulerService->deactivateSchedule($schedule);
            $message = trans_message('reports.schedule.deactivated');
        } else {
            $schedule->activate();
            $message = trans_message('reports.schedule.activated');
        }

        return AdminResponse::success(['is_active' => $schedule->fresh()->is_active], $message);
    }

    public function runNow(Request $request, int $reportId, int $scheduleId): JsonResponse
    {
        $user = $request->user();
        $organizationId = $user->current_organization_id;

        $schedule = CustomReportSchedule::with('customReport')->find($scheduleId);

        if (!$schedule || $schedule->organization_id !== $organizationId) {
            return AdminResponse::error(trans_message('reports.schedule.not_found'), 404);
        }

        try {
            $this->schedulerService->executeSchedule($schedule);

            return AdminResponse::success(null, trans_message('reports.schedule.run_success'));

        } catch (\Exception $e) {
            return AdminResponse::error(trans_message('reports.schedule.run_failed') . ': ' . $e->getMessage(), 500);
        }
    }
}

