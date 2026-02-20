<?php

namespace App\Http\Controllers\Api\V1\Admin\Schedule;

use App\Http\Controllers\Controller;
use App\Models\ScheduleTask;
use App\Models\ProjectSchedule;
use App\Http\Requests\Api\V1\Schedule\UpdateScheduleTaskRequest;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ScheduleTaskController extends Controller
{
    /**
     * Обновить задачу графика (для Gantt: drag&drop, resize)
     */
    public function update(UpdateScheduleTaskRequest $request, int $project, int $schedule, int $task): JsonResponse
    {
        try {
            $scheduleModel = ProjectSchedule::where('id', $schedule)
                ->where('project_id', $project)
                ->first();

            if (!$scheduleModel) {
                return AdminResponse::error('График не найден', Response::HTTP_NOT_FOUND);
            }

            $taskModel = ScheduleTask::where('id', $task)
                ->where('schedule_id', $schedule)
                ->first();

            if (!$taskModel) {
                return AdminResponse::error('Задача не найдена', Response::HTTP_NOT_FOUND);
            }

            $validatedData = $request->validated();

            $taskModel->update($validatedData);

            if (isset($validatedData['planned_start_date']) ||
                isset($validatedData['planned_end_date']) ||
                isset($validatedData['progress_percent'])) {
                $scheduleModel->update(['critical_path_calculated' => false]);
            }

            if (isset($validatedData['progress_percent'])) {
                $scheduleModel->recalculateProgress();
            }

            return AdminResponse::success($taskModel->fresh(), 'Задача успешно обновлена');
        } catch (\Exception $e) {
            Log::error('schedule.task.update.error', [
                'project_id' => $project,
                'schedule_id' => $schedule,
                'task_id' => $task,
                'error' => $e->getMessage(),
            ]);
            return AdminResponse::error('Внутренняя ошибка сервера', 500);
        }
    }

    /**
     * Удалить задачу
     */
    public function destroy(Request $request, int $project, int $schedule, int $task): JsonResponse
    {
        try {
            $scheduleModel = ProjectSchedule::where('id', $schedule)
                ->where('project_id', $project)
                ->first();

            if (!$scheduleModel) {
                return AdminResponse::error('График не найден', Response::HTTP_NOT_FOUND);
            }

            $taskModel = ScheduleTask::where('id', $task)
                ->where('schedule_id', $schedule)
                ->first();

            if (!$taskModel) {
                return AdminResponse::error('Задача не найдена', Response::HTTP_NOT_FOUND);
            }

            if (ScheduleTask::where('parent_task_id', $task)->exists()) {
                return AdminResponse::error('Невозможно удалить задачу, у которой есть подзадачи', Response::HTTP_CONFLICT);
            }

            $taskModel->delete();

            $scheduleModel->update(['critical_path_calculated' => false]);
            $scheduleModel->recalculateProgress();

            return AdminResponse::success(null, 'Задача успешно удалена');
        } catch (\Exception $e) {
            Log::error('schedule.task.destroy.error', [
                'project_id' => $project,
                'schedule_id' => $schedule,
                'task_id' => $task,
                'error' => $e->getMessage(),
            ]);
            return AdminResponse::error('Внутренняя ошибка сервера', 500);
        }
    }

    /**
     * Получить детальную информацию о задаче
     */
    public function show(Request $request, int $project, int $schedule, int $task): JsonResponse
    {
        try {
            $scheduleModel = ProjectSchedule::where('id', $schedule)
                ->where('project_id', $project)
                ->first();

            if (!$scheduleModel) {
                return AdminResponse::error('График не найден', Response::HTTP_NOT_FOUND);
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
                    'successorDependencies'
                ])
                ->first();

            if (!$taskModel) {
                return AdminResponse::error('Задача не найдена', Response::HTTP_NOT_FOUND);
            }

            return AdminResponse::success($taskModel);
        } catch (\Exception $e) {
            Log::error('schedule.task.show.error', [
                'project_id' => $project,
                'schedule_id' => $schedule,
                'task_id' => $task,
                'error' => $e->getMessage(),
            ]);
            return AdminResponse::error('Внутренняя ошибка сервера', 500);
        }
    }
}

