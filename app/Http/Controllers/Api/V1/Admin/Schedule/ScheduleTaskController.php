<?php

namespace App\Http\Controllers\Api\V1\Admin\Schedule;

use App\Http\Controllers\Controller;
use App\Models\ScheduleTask;
use App\Models\ProjectSchedule;
use App\Http\Requests\Api\V1\Schedule\UpdateScheduleTaskRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ScheduleTaskController extends Controller
{
    /**
     * Обновить задачу графика (для Gantt: drag&drop, resize)
     */
    public function update(Request $request, int $project, int $schedule, int $task): JsonResponse
    {
        // Находим график и проверяем принадлежность к проекту
        $scheduleModel = ProjectSchedule::where('id', $schedule)
            ->where('project_id', $project)
            ->first();

        if (!$scheduleModel) {
            return response()->json([
                'message' => 'График не найден'
            ], Response::HTTP_NOT_FOUND);
        }

        // Проверяем принадлежность задачи к графику
        $taskModel = ScheduleTask::where('id', $task)
            ->where('schedule_id', $schedule)
            ->first();

        if (!$taskModel) {
            return response()->json([
                'message' => 'Задача не найдена'
            ], Response::HTTP_NOT_FOUND);
        }

        // Валидация данных
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
            'assigned_user_id' => 'sometimes|integer|exists:users,id|nullable',
            'parent_task_id' => 'sometimes|integer|exists:schedule_tasks,id|nullable',
            'constraint_type' => 'sometimes|string|in:none,must_start_on,must_finish_on,start_no_earlier_than,start_no_later_than,finish_no_earlier_than,finish_no_later_than|nullable',
            'constraint_date' => 'sometimes|date|nullable|required_unless:constraint_type,none,null',
        ]);

        // Обновляем задачу
        $taskModel->update($validatedData);

        // Если изменились даты или прогресс, помечаем график для пересчета критического пути
        if (isset($validatedData['planned_start_date']) || 
            isset($validatedData['planned_end_date']) || 
            isset($validatedData['progress_percent'])) {
            $scheduleModel->update(['critical_path_calculated' => false]);
        }

        // Пересчитываем общий прогресс графика
        if (isset($validatedData['progress_percent'])) {
            $scheduleModel->recalculateProgress();
        }

        return response()->json([
            'message' => 'Задача успешно обновлена',
            'data' => $taskModel->fresh()
        ]);
    }

    /**
     * Удалить задачу
     */
    public function destroy(Request $request, int $project, int $schedule, int $task): JsonResponse
    {
        // Находим график и проверяем принадлежность к проекту
        $scheduleModel = ProjectSchedule::where('id', $schedule)
            ->where('project_id', $project)
            ->first();

        if (!$scheduleModel) {
            return response()->json([
                'message' => 'График не найден'
            ], Response::HTTP_NOT_FOUND);
        }

        // Проверяем принадлежность задачи к графику
        $taskModel = ScheduleTask::where('id', $task)
            ->where('schedule_id', $schedule)
            ->first();

        if (!$taskModel) {
            return response()->json([
                'message' => 'Задача не найдена'
            ], Response::HTTP_NOT_FOUND);
        }

        // Проверяем, есть ли дочерние задачи
        $hasChildren = ScheduleTask::where('parent_task_id', $task)->exists();
        
        if ($hasChildren) {
            return response()->json([
                'message' => 'Невозможно удалить задачу, у которой есть подзадачи'
            ], Response::HTTP_CONFLICT);
        }

        // Удаляем задачу
        $taskModel->delete();

        // Помечаем график для пересчета
        $scheduleModel->update(['critical_path_calculated' => false]);
        $scheduleModel->recalculateProgress();

        return response()->json([
            'message' => 'Задача успешно удалена'
        ]);
    }

    /**
     * Получить детальную информацию о задаче
     */
    public function show(Request $request, int $project, int $schedule, int $task): JsonResponse
    {
        // Находим график и проверяем принадлежность к проекту
        $scheduleModel = ProjectSchedule::where('id', $schedule)
            ->where('project_id', $project)
            ->first();

        if (!$scheduleModel) {
            return response()->json([
                'message' => 'График не найден'
            ], Response::HTTP_NOT_FOUND);
        }

        // Проверяем принадлежность задачи к графику
        $taskModel = ScheduleTask::where('id', $task)
            ->where('schedule_id', $schedule)
            ->with([
                'parentTask',
                'childTasks',
                'assignedUser',
                'workType',
                'predecessorDependencies',
                'successorDependencies'
            ])
            ->first();

        if (!$taskModel) {
            return response()->json([
                'message' => 'Задача не найдена'
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'data' => $taskModel
        ]);
    }
}

