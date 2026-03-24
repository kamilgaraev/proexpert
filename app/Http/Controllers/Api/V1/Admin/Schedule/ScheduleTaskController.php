<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Schedule;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Schedule\UpdateScheduleTaskRequest;
use App\Http\Resources\Api\V1\Schedule\ScheduleTaskResource;
use App\Http\Responses\AdminResponse;
use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use App\Services\Schedule\ScheduleTaskMutationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use function trans_message;

class ScheduleTaskController extends Controller
{
    public function __construct(
        protected ScheduleTaskMutationService $scheduleTaskMutationService,
    ) {
    }

    public function update(UpdateScheduleTaskRequest $request, int $project, int $schedule, int $task): JsonResponse
    {
        try {
            $scheduleModel = ProjectSchedule::where('id', $schedule)
                ->where('project_id', $project)
                ->first();

            if (!$scheduleModel) {
                return AdminResponse::error(trans_message('schedule_management.schedule_not_found'), Response::HTTP_NOT_FOUND);
            }

            $taskModel = ScheduleTask::where('id', $task)
                ->where('schedule_id', $schedule)
                ->first();

            if (!$taskModel) {
                return AdminResponse::error(trans_message('schedule_management.task_not_found'), Response::HTTP_NOT_FOUND);
            }

            $result = $this->scheduleTaskMutationService->updateTask($scheduleModel, $taskModel, $request->validated());

            return AdminResponse::success([
                'task' => new ScheduleTaskResource($result['task']),
                'affected_tasks' => ScheduleTaskResource::collection($result['affected_tasks']),
            ], trans_message('schedule_management.task_updated'));
        } catch (\Throwable $e) {
            Log::error('schedule.task.update.error', [
                'project_id' => $project,
                'schedule_id' => $schedule,
                'task_id' => $task,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('schedule_management.task_update_error'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function destroy(Request $request, int $project, int $schedule, int $task): JsonResponse
    {
        try {
            $scheduleModel = ProjectSchedule::where('id', $schedule)
                ->where('project_id', $project)
                ->first();

            if (!$scheduleModel) {
                return AdminResponse::error(trans_message('schedule_management.schedule_not_found'), Response::HTTP_NOT_FOUND);
            }

            $taskModel = ScheduleTask::where('id', $task)
                ->where('schedule_id', $schedule)
                ->first();

            if (!$taskModel) {
                return AdminResponse::error(trans_message('schedule_management.task_not_found'), Response::HTTP_NOT_FOUND);
            }

            if (ScheduleTask::where('parent_task_id', $task)->exists()) {
                return AdminResponse::error(trans_message('schedule_management.task_delete_has_children'), Response::HTTP_CONFLICT);
            }

            $taskModel->delete();

            $scheduleModel->update(['critical_path_calculated' => false]);
            $scheduleModel->recalculateProgress();

            return AdminResponse::success(null, trans_message('schedule_management.task_deleted'));
        } catch (\Throwable $e) {
            Log::error('schedule.task.destroy.error', [
                'project_id' => $project,
                'schedule_id' => $schedule,
                'task_id' => $task,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('schedule_management.task_delete_error'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function show(Request $request, int $project, int $schedule, int $task): JsonResponse
    {
        try {
            $scheduleModel = ProjectSchedule::where('id', $schedule)
                ->where('project_id', $project)
                ->first();

            if (!$scheduleModel) {
                return AdminResponse::error(trans_message('schedule_management.schedule_not_found'), Response::HTTP_NOT_FOUND);
            }

            $taskModel = ScheduleTask::where('id', $task)
                ->where('schedule_id', $schedule)
                ->with([
                    'parentTask',
                    'childTasks',
                    'assignedUser',
                    'workType',
                    'measurementUnit',
                    'predecessorDependencies',
                    'successorDependencies',
                ])
                ->withCount('completedWorks')
                ->with('intervals')
                ->first();

            if (!$taskModel) {
                return AdminResponse::error(trans_message('schedule_management.task_not_found'), Response::HTTP_NOT_FOUND);
            }

            return AdminResponse::success(new ScheduleTaskResource($taskModel));
        } catch (\Throwable $e) {
            Log::error('schedule.task.show.error', [
                'project_id' => $project,
                'schedule_id' => $schedule,
                'task_id' => $task,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('schedule_management.task_load_error'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
