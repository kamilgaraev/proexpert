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
    public function update(Request $request, int $project, int $schedule, int $task): JsonResponse
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

            $validatedData = $request->validate([
                'name' => 'sometimes|string|max:255',
                'description' => 'sometimes|string|nullable',
                'planned_start_date' => 'sometimes|date|nullable',
                'planned_end_date' => 'sometimes|date|nullable|after_or_equal:planned_start_date',
                'planned_duration_days' => 'sometimes|integer|min:1|nullable',
                'actual_start_date' => 'sometimes|date|nullable',
                'actual_end_date' => 'sometimes|date|nullable|after_or_equal:actual_start_date',
                'progress_percent' => 'sometimes|numeric|min:0|max:100',
                'status' => 'sometimes|string|in:not_started,in_progress,completed,cancelled,on_hold',
                'priority' => 'sometimes|string|in:low,normal,high,critical',
                'estimated_cost' => 'sometimes|numeric|min:0|nullable',
                'quantity' => 'sometimes|numeric|min:0|nullable',
                'assigned_user_id' => 'sometimes|integer|exists:users,id|nullable',
                'parent_task_id' => 'sometimes|integer|exists:schedule_tasks,id|nullable',
                'constraint_type' => 'sometimes|string|in:none,must_start_on,must_finish_on,start_no_earlier_than,start_no_later_than,finish_no_earlier_than,finish_no_later_than|nullable',
                'constraint_date' => 'sometimes|date|nullable|required_unless:constraint_type,none,null',
            ]);

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
        } catch (\Illuminate\Validation\ValidationException $e) {
            return AdminResponse::error('Ошибка валидации', 422, $e->errors());
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

