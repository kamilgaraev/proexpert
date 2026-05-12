<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Schedule;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Schedule\StoreTaskResourceRequest;
use App\Http\Requests\Api\V1\Schedule\UpdateScheduleTaskRequest;
use App\Http\Resources\Api\V1\Schedule\ScheduleTaskResource;
use App\Http\Responses\AdminResponse;
use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use App\Services\Logging\LoggingService;
use App\Services\Schedule\ScheduleTaskResourceAssignmentService;
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
        protected ScheduleTaskResourceAssignmentService $scheduleTaskResourceAssignmentService,
        protected LoggingService $logging,
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
            $this->recordScheduleTaskAudit('schedule_task.updated', $scheduleModel, $result['task'], $request);

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
            $this->recordScheduleTaskAudit('schedule_task.deleted', $scheduleModel, $taskModel, $request);

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

    public function storeResource(StoreTaskResourceRequest $request, int $project, int $schedule, int $task): JsonResponse
    {
        try {
            $scheduleModel = $this->findProjectSchedule($project, $schedule);

            if (!$scheduleModel) {
                return AdminResponse::error(trans_message('schedule_management.schedule_not_found'), Response::HTTP_NOT_FOUND);
            }

            $taskModel = $this->findScheduleTask($scheduleModel, $task);

            if (!$taskModel) {
                return AdminResponse::error(trans_message('schedule_management.task_not_found'), Response::HTTP_NOT_FOUND);
            }

            $user = $request->user();

            if (!$user) {
                return AdminResponse::error(trans_message('errors.unauthenticated'), Response::HTTP_UNAUTHORIZED);
            }

            $resource = $this->scheduleTaskResourceAssignmentService->assign(
                $scheduleModel,
                $taskModel,
                $request->validated(),
                $user
            );
            $this->recordScheduleTaskAudit('schedule_task.resource.assigned', $scheduleModel, $taskModel, $request);

            return AdminResponse::success(
                $this->scheduleTaskResourceAssignmentService->toResponse($resource),
                trans_message('schedule_management.task_resource_assigned'),
                Response::HTTP_CREATED
            );
        } catch (\Throwable $e) {
            Log::error('schedule.task.resource.store.error', [
                'project_id' => $project,
                'schedule_id' => $schedule,
                'task_id' => $task,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('schedule_management.task_resource_assign_error'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function destroyResource(Request $request, int $project, int $schedule, int $task, int $resource): JsonResponse
    {
        try {
            $scheduleModel = $this->findProjectSchedule($project, $schedule);

            if (!$scheduleModel) {
                return AdminResponse::error(trans_message('schedule_management.schedule_not_found'), Response::HTTP_NOT_FOUND);
            }

            $taskModel = $this->findScheduleTask($scheduleModel, $task);

            if (!$taskModel) {
                return AdminResponse::error(trans_message('schedule_management.task_not_found'), Response::HTTP_NOT_FOUND);
            }

            $resourceModel = $this->scheduleTaskResourceAssignmentService->remove($scheduleModel, $taskModel, $resource);

            if (!$resourceModel) {
                return AdminResponse::error(trans_message('schedule_management.task_resource_not_found'), Response::HTTP_NOT_FOUND);
            }

            $this->recordScheduleTaskAudit('schedule_task.resource.removed', $scheduleModel, $taskModel, $request);

            return AdminResponse::success(null, trans_message('schedule_management.task_resource_removed'));
        } catch (\Throwable $e) {
            Log::error('schedule.task.resource.destroy.error', [
                'project_id' => $project,
                'schedule_id' => $schedule,
                'task_id' => $task,
                'resource_id' => $resource,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('schedule_management.task_resource_remove_error'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function findProjectSchedule(int $project, int $schedule): ?ProjectSchedule
    {
        return ProjectSchedule::query()
            ->where('id', $schedule)
            ->where('project_id', $project)
            ->first();
    }

    private function findScheduleTask(ProjectSchedule $schedule, int $task): ?ScheduleTask
    {
        return ScheduleTask::query()
            ->where('id', $task)
            ->where('schedule_id', $schedule->id)
            ->first();
    }

    private function recordScheduleTaskAudit(
        string $event,
        ProjectSchedule $schedule,
        ScheduleTask $task,
        Request $request
    ): void {
        $this->logging->audit($event, [
            'organization_id' => $task->organization_id ?? $schedule->organization_id,
            'project_id' => $schedule->project_id,
            'schedule_id' => $schedule->id,
            'schedule_name' => $schedule->name,
            'task_id' => $task->id,
            'task_name' => $task->name,
            'performed_by' => $request->user()?->id,
        ]);
    }
}
