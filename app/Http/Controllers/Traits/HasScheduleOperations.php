<?php

namespace App\Http\Controllers\Traits;

use App\Repositories\Interfaces\ProjectScheduleRepositoryInterface;
use App\Services\Schedule\CriticalPathService;
use App\Http\Requests\Api\V1\Schedule\CreateProjectScheduleRequest;
use App\Http\Requests\Api\V1\Schedule\UpdateProjectScheduleRequest;
use App\Http\Requests\Api\V1\Schedule\CreateScheduleTaskRequest;
use App\Http\Requests\Api\V1\Schedule\CreateTaskDependencyRequest;
use App\Http\Resources\Api\V1\Schedule\ProjectScheduleResource;
use App\Http\Resources\Api\V1\Schedule\ProjectScheduleCollection;
use App\Models\ScheduleTask;
use App\Models\TaskDependency;
use App\Exceptions\Schedule\ScheduleNotFoundException;
use App\Exceptions\Schedule\CircularDependencyException;
use App\Exceptions\Schedule\ScheduleValidationException;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

trait HasScheduleOperations
{
    /**
     * Получить график или выбросить исключение
     */
    protected function findScheduleOrFail(int $scheduleId, callable $finder): object
    {
        $schedule = $finder($scheduleId);
        
        if (!$schedule) {
            throw new ScheduleNotFoundException($scheduleId);
        }
        
        return $schedule;
    }

    /**
     * Создать график проекта
     */
    protected function createSchedule(CreateProjectScheduleRequest $request, int $organizationId): JsonResponse
    {
        $data = $request->validated();
        $data['organization_id'] = $organizationId;
        $data['created_by_user_id'] = $request->user()->id;

        // Устанавливаем значения по умолчанию
        $data['status'] = $data['status'] ?? 'draft';
        $data['overall_progress_percent'] = 0;
        $data['critical_path_calculated'] = false;

        $schedule = $this->scheduleRepository->create($data);

        return response()->json([
            'message' => 'График проекта успешно создан',
            'data' => new ProjectScheduleResource($schedule->load(['project', 'createdBy']))
        ], 201);
    }

    /**
     * Обновить график проекта
     */
    protected function updateSchedule(int $scheduleId, UpdateProjectScheduleRequest $request, callable $finder): JsonResponse
    {
        $schedule = $this->findScheduleOrFail($scheduleId, $finder);

        $data = $request->validated();
        
        // Не позволяем изменять organization_id и created_by_user_id через update
        unset($data['organization_id'], $data['created_by_user_id']);
        
        if (isset($data['is_template']) && $data['is_template']) {
            unset($data['project_id']); // Шаблоны не привязаны к проектам (для organization-based)
        }

        $this->scheduleRepository->update($schedule->id, $data);
        $schedule = $this->scheduleRepository->find($schedule->id);

        return response()->json([
            'message' => 'График проекта обновлен',
            'data' => new ProjectScheduleResource($schedule->load(['project', 'createdBy']))
        ]);
    }

    /**
     * Удалить график проекта
     */
    protected function deleteSchedule(int $scheduleId, callable $finder): JsonResponse
    {
        $schedule = $this->findScheduleOrFail($scheduleId, $finder);

        $this->scheduleRepository->delete($schedule->id);

        return response()->json(['message' => 'График проекта удален'], 200);
    }

    /**
     * Рассчитать критический путь для графика
     */
    protected function calculateCriticalPathForSchedule(int $scheduleId, callable $finder): JsonResponse
    {
        $schedule = $this->findScheduleOrFail($scheduleId, $finder);

        try {
            $criticalPath = $this->criticalPathService->calculateCriticalPath($schedule);
            
            // Обновляем флаг что критический путь рассчитан
            $this->scheduleRepository->update($schedule->id, [
                'critical_path_calculated' => true,
                'critical_path_duration_days' => $criticalPath['duration']
            ]);

            return response()->json([
                'message' => 'Критический путь рассчитан',
                'data' => $criticalPath
            ]);
        } catch (CircularDependencyException $e) {
            Log::warning('Обнаружены циклические зависимости при расчете критического пути', [
                'schedule_id' => $scheduleId,
                'cycle_tasks' => $e->getCycleTasks()
            ]);
            return response()->json([
                'message' => $e->getMessage(),
                'cycle_tasks' => $e->getCycleTasks()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Ошибка при расчете критического пути', [
                'schedule_id' => $scheduleId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'message' => 'Внутренняя ошибка сервера при расчете критического пути'
            ], 500);
        }
    }

    /**
     * Сохранить базовый план графика
     */
    protected function saveBaselineForSchedule(int $scheduleId, Request $request, callable $finder): JsonResponse
    {
        $schedule = $this->findScheduleOrFail($scheduleId, $finder);

        try {
            $this->scheduleRepository->saveBaseline($schedule->id, $request->user()->id);

            return response()->json([
                'message' => 'Базовый план сохранен'
            ]);
        } catch (\Exception $e) {
            Log::error('Ошибка при сохранении базового плана', [
                'schedule_id' => $scheduleId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'message' => 'Внутренняя ошибка сервера при сохранении базового плана'
            ], 500);
        }
    }

    /**
     * Очистить базовый план графика
     */
    protected function clearBaselineForSchedule(int $scheduleId, callable $finder): JsonResponse
    {
        $schedule = $this->findScheduleOrFail($scheduleId, $finder);

        try {
            $this->scheduleRepository->clearBaseline($schedule->id);

            return response()->json([
                'message' => 'Базовый план очищен'
            ]);
        } catch (\Exception $e) {
            Log::error('Ошибка при очистке базового плана', [
                'schedule_id' => $scheduleId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'message' => 'Внутренняя ошибка сервера при очистке базового плана'
            ], 500);
        }
    }

    /**
     * Получить задачи расписания
     */
    protected function getScheduleTasks(int $scheduleId, callable $finder): JsonResponse
    {
        $schedule = $this->findScheduleOrFail($scheduleId, $finder);

        $tasks = $schedule->tasks()->with(['assignedUser', 'workType'])->get();

        return response()->json([
            'data' => $tasks
        ]);
    }

    /**
     * Создать новую задачу в расписании
     */
    protected function createScheduleTask(int $scheduleId, CreateScheduleTaskRequest $request, int $organizationId, callable $finder): JsonResponse
    {
        Log::info('[ScheduleTask] Начало создания задачи', [
            'schedule_id' => $scheduleId,
            'organization_id' => $organizationId,
            'user_id' => $request->user()->id,
        ]);

        try {
            $schedule = $this->findScheduleOrFail($scheduleId, $finder);
            
            Log::info('[ScheduleTask] График найден', [
                'schedule_id' => $schedule->id,
                'schedule_name' => $schedule->name,
            ]);
        } catch (\Exception $e) {
            Log::error('[ScheduleTask] График не найден', [
                'schedule_id' => $scheduleId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }

        $data = $request->validated();
        $data['schedule_id'] = $schedule->id;
        $data['organization_id'] = $organizationId;
        $data['created_by_user_id'] = $request->user()->id;

        Log::info('[ScheduleTask] Данные для создания задачи', [
            'data' => $data,
        ]);

        try {
            Log::info('[ScheduleTask] Попытка создания модели ScheduleTask');
            $task = ScheduleTask::create($data);
            
            Log::info('[ScheduleTask] Модель создана успешно', [
                'task_id' => $task->id,
                'task_name' => $task->name,
            ]);
            
            // Загружаем связанные данные для ответа
            Log::info('[ScheduleTask] Загрузка связанных данных');
            $task->load(['assignedUser', 'workType', 'parentTask']);
            
            Log::info('[ScheduleTask] Задача успешно создана', [
                'task_id' => $task->id,
            ]);

            return response()->json([
                'message' => 'Задача успешно создана',
                'data' => $task
            ], 201);
        } catch (ScheduleValidationException $e) {
            Log::warning('[ScheduleTask] Ошибка валидации задачи', [
                'schedule_id' => $scheduleId,
                'errors' => $e->getErrors(),
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->getErrors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('[ScheduleTask] ОШИБКА при создании задачи', [
                'schedule_id' => $scheduleId,
                'organization_id' => $organizationId,
                'error_message' => $e->getMessage(),
                'error_class' => get_class($e),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'data' => $data,
            ]);
            
            return response()->json([
                'message' => 'Внутренняя ошибка сервера при создании задачи'
            ], 500);
        }
    }

    /**
     * Получить зависимости расписания
     */
    protected function getScheduleDependencies(int $scheduleId, callable $finder): JsonResponse
    {
        $schedule = $this->findScheduleOrFail($scheduleId, $finder);

        $dependencies = $schedule->dependencies()
            ->with(['predecessorTask', 'successorTask'])
            ->get();

        return response()->json([
            'data' => $dependencies
        ]);
    }

    /**
     * Создать новую зависимость между задачами
     */
    protected function createScheduleDependency(int $scheduleId, CreateTaskDependencyRequest $request, int $organizationId, callable $finder): JsonResponse
    {
        $schedule = $this->findScheduleOrFail($scheduleId, $finder);

        $data = $request->validated();
        $data['schedule_id'] = $schedule->id;
        $data['organization_id'] = $organizationId;
        $data['created_by_user_id'] = $request->user()->id;
        
        // Устанавливаем значения по умолчанию
        $data['is_active'] = true;
        $data['validation_status'] = 'valid';

        try {
            $dependency = TaskDependency::create($data);
            
            // Загружаем связанные данные для ответа
            $dependency->load(['predecessorTask', 'successorTask', 'createdBy']);

            return response()->json([
                'message' => 'Зависимость между задачами успешно создана',
                'data' => [
                    'id' => $dependency->id,
                    'dependency_type' => $dependency->dependency_type->value,
                    'dependency_type_label' => $dependency->dependency_type->label(),
                    'lag_days' => $dependency->lag_days,
                    'lag_hours' => $dependency->lag_hours,
                    'lag_type' => $dependency->lag_type,
                    'description' => $dependency->description,
                    'is_hard_constraint' => $dependency->is_hard_constraint,
                    'priority' => $dependency->priority,
                    'is_active' => $dependency->is_active,
                    'validation_status' => $dependency->validation_status,
                    'created_at' => $dependency->created_at,
                    'predecessor_task' => [
                        'id' => $dependency->predecessorTask->id,
                        'name' => $dependency->predecessorTask->name,
                        'planned_start_date' => $dependency->predecessorTask->planned_start_date,
                        'planned_end_date' => $dependency->predecessorTask->planned_end_date,
                    ],
                    'successor_task' => [
                        'id' => $dependency->successorTask->id,
                        'name' => $dependency->successorTask->name,
                        'planned_start_date' => $dependency->successorTask->planned_start_date,
                        'planned_end_date' => $dependency->successorTask->planned_end_date,
                    ],
                    'created_by' => [
                        'id' => $dependency->createdBy->id,
                        'name' => $dependency->createdBy->name,
                        'email' => $dependency->createdBy->email,
                    ]
                ]
            ], 201);
        } catch (CircularDependencyException $e) {
            Log::warning('Попытка создания циклической зависимости', [
                'schedule_id' => $scheduleId,
                'cycle_tasks' => $e->getCycleTasks(),
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'message' => $e->getMessage(),
                'cycle_tasks' => $e->getCycleTasks()
            ], 422);
        } catch (ScheduleValidationException $e) {
            Log::warning('Ошибка валидации зависимости', [
                'schedule_id' => $scheduleId,
                'errors' => $e->getErrors(),
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->getErrors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Ошибка при создании зависимости', [
                'schedule_id' => $scheduleId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'message' => 'Внутренняя ошибка сервера при создании зависимости'
            ], 500);
        }
    }

    /**
     * Получить конфликты ресурсов в расписании
     */
    protected function getResourceConflicts(int $scheduleId, int $organizationId, callable $finder): JsonResponse
    {
        $schedule = $this->findScheduleOrFail($scheduleId, $finder);

        try {
            // Загружаем детальную информацию о конфликтах
            $schedule->load([
                'tasks' => function ($query) {
                    $query->whereHas('resources', function ($q) {
                        $q->where('has_conflicts', true);
                    })->with(['resources', 'assignedUser']);
                },
                'resources' => function ($query) {
                    $query->where('has_conflicts', true);
                }
            ]);

            $hasConflicts = $schedule->tasks->isNotEmpty() || $schedule->resources->isNotEmpty();

            if (!$hasConflicts) {
                return response()->json([
                    'data' => [],
                    'meta' => [
                        'conflicts_count' => 0,
                        'has_conflicts' => false,
                        'message' => 'Конфликтов ресурсов не обнаружено'
                    ]
                ]);
            }

            return response()->json([
                'data' => [
                    'schedule_id' => $schedule->id,
                    'schedule_name' => $schedule->name,
                    'conflicted_tasks' => $schedule->tasks,
                    'conflicted_resources' => $schedule->resources,
                ],
                'meta' => [
                    'conflicts_count' => $schedule->tasks->count() + $schedule->resources->count(),
                    'has_conflicts' => true,
                    'message' => 'Обнаружены конфликты ресурсов'
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Ошибка при получении конфликтов ресурсов', [
                'schedule_id' => $scheduleId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'message' => 'Внутренняя ошибка сервера при получении конфликтов ресурсов'
            ], 500);
        }
    }
}

