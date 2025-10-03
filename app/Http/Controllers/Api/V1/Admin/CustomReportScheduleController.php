<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomReport;
use App\Models\CustomReportSchedule;
use App\Services\Report\CustomReportSchedulerService;
use App\Http\Requests\Api\V1\Admin\CustomReport\CreateScheduleRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomReportScheduleController extends Controller
{
    public function __construct(
        protected CustomReportSchedulerService $schedulerService
    ) {
        $this->middleware('can:view-reports');
    }

    public function index(Request $request, int $reportId): JsonResponse
    {
        $user = $request->user();
        $organizationId = $user->current_organization_id;

        $report = CustomReport::find($reportId);

        if (!$report || !$report->canBeViewedBy($user->id, $organizationId)) {
            return response()->json([
                'success' => false,
                'message' => 'Отчет не найден',
            ], 404);
        }

        $schedules = $report->schedules()
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $schedules,
        ]);
    }

    public function store(CreateScheduleRequest $request, int $reportId): JsonResponse
    {
        $user = $request->user();
        $organizationId = $user->current_organization_id;

        $report = CustomReport::find($reportId);

        if (!$report || !$report->canBeViewedBy($user->id, $organizationId)) {
            return response()->json([
                'success' => false,
                'message' => 'Отчет не найден',
            ], 404);
        }

        try {
            $scheduleData = array_merge($request->validated(), [
                'user_id' => $user->id,
            ]);

            $schedule = $this->schedulerService->createSchedule($report, $scheduleData);

            return response()->json([
                'success' => true,
                'message' => 'Расписание успешно создано',
                'data' => $schedule->load('user'),
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function show(Request $request, int $reportId, int $scheduleId): JsonResponse
    {
        $user = $request->user();
        $organizationId = $user->current_organization_id;

        $schedule = CustomReportSchedule::with(['customReport', 'user', 'lastExecution'])
            ->find($scheduleId);

        if (!$schedule || $schedule->organization_id !== $organizationId) {
            return response()->json([
                'success' => false,
                'message' => 'Расписание не найдено',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $schedule,
        ]);
    }

    public function update(Request $request, int $reportId, int $scheduleId): JsonResponse
    {
        $user = $request->user();
        $organizationId = $user->current_organization_id;

        $schedule = CustomReportSchedule::find($scheduleId);

        if (!$schedule || $schedule->organization_id !== $organizationId) {
            return response()->json([
                'success' => false,
                'message' => 'Расписание не найдено',
            ], 404);
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

            return response()->json([
                'success' => true,
                'message' => 'Расписание успешно обновлено',
                'data' => $schedule,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function destroy(Request $request, int $reportId, int $scheduleId): JsonResponse
    {
        $user = $request->user();
        $organizationId = $user->current_organization_id;

        $schedule = CustomReportSchedule::find($scheduleId);

        if (!$schedule || $schedule->organization_id !== $organizationId) {
            return response()->json([
                'success' => false,
                'message' => 'Расписание не найдено',
            ], 404);
        }

        $this->schedulerService->deleteSchedule($schedule);

        return response()->json([
            'success' => true,
            'message' => 'Расписание успешно удалено',
        ]);
    }

    public function toggle(Request $request, int $reportId, int $scheduleId): JsonResponse
    {
        $user = $request->user();
        $organizationId = $user->current_organization_id;

        $schedule = CustomReportSchedule::find($scheduleId);

        if (!$schedule || $schedule->organization_id !== $organizationId) {
            return response()->json([
                'success' => false,
                'message' => 'Расписание не найдено',
            ], 404);
        }

        if ($schedule->is_active) {
            $this->schedulerService->deactivateSchedule($schedule);
            $message = 'Расписание деактивировано';
        } else {
            $schedule->activate();
            $message = 'Расписание активировано';
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => ['is_active' => $schedule->fresh()->is_active],
        ]);
    }

    public function runNow(Request $request, int $reportId, int $scheduleId): JsonResponse
    {
        $user = $request->user();
        $organizationId = $user->current_organization_id;

        $schedule = CustomReportSchedule::with('customReport')->find($scheduleId);

        if (!$schedule || $schedule->organization_id !== $organizationId) {
            return response()->json([
                'success' => false,
                'message' => 'Расписание не найдено',
            ], 404);
        }

        try {
            $this->schedulerService->executeSchedule($schedule);

            return response()->json([
                'success' => true,
                'message' => 'Отчет успешно выполнен и отправлен получателям',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка выполнения отчета: ' . $e->getMessage(),
            ], 500);
        }
    }
}

