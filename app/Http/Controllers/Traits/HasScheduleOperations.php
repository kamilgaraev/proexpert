<?php

declare(strict_types=1);

namespace App\Http\Controllers\Traits;

use App\Exceptions\Schedule\CircularDependencyException;
use App\Exceptions\Schedule\ScheduleNotFoundException;
use App\Exceptions\Schedule\ScheduleValidationException;
use App\Http\Requests\Api\V1\Schedule\CreateProjectScheduleRequest;
use App\Http\Requests\Api\V1\Schedule\CreateScheduleTaskRequest;
use App\Http\Requests\Api\V1\Schedule\CreateTaskDependencyRequest;
use App\Http\Requests\Api\V1\Schedule\UpdateProjectScheduleRequest;
use App\Http\Requests\Api\V1\Schedule\UpdateTaskDependencyRequest;
use App\Http\Resources\Api\V1\Schedule\ProjectScheduleResource;
use App\Http\Resources\Api\V1\Schedule\ScheduleTaskResource;
use App\Http\Responses\AdminResponse;
use App\Models\ScheduleTask;
use App\Models\TaskDependency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use function trans_message;

trait HasScheduleOperations
{
    protected function findScheduleOrFail(int $scheduleId, callable $finder): object
    {
        $schedule = $finder($scheduleId);

        if (!$schedule) {
            throw new ScheduleNotFoundException($scheduleId);
        }

        return $schedule;
    }

    protected function createSchedule(CreateProjectScheduleRequest $request, int $organizationId): JsonResponse
    {
        $data = $request->validated();
        $data['organization_id'] = $organizationId;
        $data['created_by_user_id'] = $request->user()->id;
        $data['status'] = $data['status'] ?? 'draft';
        $data['overall_progress_percent'] = 0;
        $data['critical_path_calculated'] = false;

        $schedule = $this->scheduleRepository->create($data);

        return AdminResponse::success(
            new ProjectScheduleResource($schedule->load(['project', 'createdBy'])),
            trans_message('schedule_management.schedule_created'),
            201
        );
    }

    protected function updateSchedule(
        int $scheduleId,
        UpdateProjectScheduleRequest $request,
        callable $finder
    ): JsonResponse {
        $schedule = $this->findScheduleOrFail($scheduleId, $finder);

        $data = $request->validated();
        unset($data['organization_id'], $data['created_by_user_id']);

        if (($data['is_template'] ?? false) === true) {
            unset($data['project_id']);
        }

        $this->scheduleRepository->update($schedule->id, $data);
        $schedule = $this->scheduleRepository->find($schedule->id);

        return AdminResponse::success(
            new ProjectScheduleResource($schedule->load(['project', 'createdBy'])),
            trans_message('schedule_management.schedule_updated')
        );
    }

    protected function deleteSchedule(int $scheduleId, callable $finder): JsonResponse
    {
        $schedule = $this->findScheduleOrFail($scheduleId, $finder);

        $this->scheduleRepository->delete($schedule->id);

        return AdminResponse::success(null, trans_message('schedule_management.schedule_deleted'));
    }

    protected function calculateCriticalPathForSchedule(int $scheduleId, callable $finder): JsonResponse
    {
        $schedule = $this->findScheduleOrFail($scheduleId, $finder);

        try {
            $criticalPath = $this->criticalPathService->calculateCriticalPath($schedule);

            $this->scheduleRepository->update($schedule->id, [
                'critical_path_calculated' => true,
                'critical_path_duration_days' => $criticalPath['duration'],
            ]);

            return AdminResponse::success(
                $criticalPath,
                trans_message('schedule_management.critical_path_calculated')
            );
        } catch (CircularDependencyException $e) {
            Log::warning('[HasScheduleOperations.calculateCriticalPathForSchedule] Circular dependency detected', [
                'schedule_id' => $scheduleId,
                'cycle_tasks' => $e->getCycleTasks(),
            ]);

            return AdminResponse::error($e->getMessage(), 422, ['cycle_tasks' => $e->getCycleTasks()]);
        } catch (\Throwable $e) {
            Log::error('[HasScheduleOperations.calculateCriticalPathForSchedule] Unexpected error', [
                'schedule_id' => $scheduleId,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return AdminResponse::error(trans_message('schedule_management.critical_path_error'), 500);
        }
    }

    protected function saveBaselineForSchedule(int $scheduleId, Request $request, callable $finder): JsonResponse
    {
        $schedule = $this->findScheduleOrFail($scheduleId, $finder);

        try {
            $this->scheduleRepository->saveBaseline($schedule->id, $request->user()->id);

            return AdminResponse::success(null, trans_message('schedule_management.baseline_saved'));
        } catch (\Throwable $e) {
            Log::error('[HasScheduleOperations.saveBaselineForSchedule] Unexpected error', [
                'schedule_id' => $scheduleId,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return AdminResponse::error(trans_message('schedule_management.baseline_save_error'), 500);
        }
    }

    protected function clearBaselineForSchedule(int $scheduleId, callable $finder): JsonResponse
    {
        $schedule = $this->findScheduleOrFail($scheduleId, $finder);

        try {
            $this->scheduleRepository->clearBaseline($schedule->id);

            return AdminResponse::success(null, trans_message('schedule_management.baseline_cleared'));
        } catch (\Throwable $e) {
            Log::error('[HasScheduleOperations.clearBaselineForSchedule] Unexpected error', [
                'schedule_id' => $scheduleId,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return AdminResponse::error(trans_message('schedule_management.baseline_clear_error'), 500);
        }
    }

    protected function getScheduleTasks(int $scheduleId, callable $finder): JsonResponse
    {
        $schedule = $this->findScheduleOrFail($scheduleId, $finder);
        $tasks = $schedule->tasks()->with(['assignedUser', 'workType'])->get();

        return AdminResponse::success($tasks);
    }

    protected function createScheduleTask(
        int $scheduleId,
        CreateScheduleTaskRequest $request,
        int $organizationId,
        callable $finder
    ): JsonResponse {
        Log::info('[HasScheduleOperations.createScheduleTask] Started', [
            'schedule_id' => $scheduleId,
            'organization_id' => $organizationId,
            'user_id' => $request->user()->id,
        ]);

        $schedule = $this->findScheduleOrFail($scheduleId, $finder);
        $data = $request->validated();
        $data['schedule_id'] = $schedule->id;
        $data['organization_id'] = $organizationId;
        $data['created_by_user_id'] = $request->user()->id;

        $insertAfterId = $data['insert_after_id'] ?? null;
        unset($data['insert_after_id']);

        if ($insertAfterId !== null) {
            $taskService = app(\App\Services\Schedule\ScheduleTaskService::class);
            $data['sort_order'] = $taskService->insertTaskAfter(
                $schedule->id,
                $insertAfterId,
                $data['parent_task_id'] ?? null
            );
        } elseif (empty($data['sort_order'])) {
            $taskService = app(\App\Services\Schedule\ScheduleTaskService::class);
            $data['sort_order'] = $taskService->getNextSortOrder(
                $schedule->id,
                $data['parent_task_id'] ?? null
            );
        }

        try {
            $task = ScheduleTask::create($data);

            if (array_key_exists('intervals', $data)) {
                $scheduleTaskService = app(\App\Services\Schedule\ScheduleTaskService::class);
                $scheduleTaskService->syncTaskIntervals($task, $data['intervals']);
                $task->refresh();
            }

            $task->load(['assignedUser', 'workType', 'parentTask', 'intervals']);

            return AdminResponse::success(
                new ScheduleTaskResource($task),
                trans_message('schedule_management.task_created'),
                201
            );
        } catch (ScheduleValidationException $e) {
            Log::warning('[HasScheduleOperations.createScheduleTask] Validation failed', [
                'schedule_id' => $scheduleId,
                'errors' => $e->getErrors(),
                'message' => $e->getMessage(),
            ]);

            return AdminResponse::error($e->getMessage(), 422, $e->getErrors());
        } catch (\Throwable $e) {
            Log::error('[HasScheduleOperations.createScheduleTask] Unexpected error', [
                'schedule_id' => $scheduleId,
                'organization_id' => $organizationId,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data' => $data,
            ]);

            return AdminResponse::error(trans_message('schedule_management.task_create_error'), 500);
        }
    }

    protected function getScheduleDependencies(int $scheduleId, callable $finder): JsonResponse
    {
        $schedule = $this->findScheduleOrFail($scheduleId, $finder);

        $dependencies = $schedule->dependencies()
            ->with(['predecessorTask', 'successorTask'])
            ->get();

        return AdminResponse::success($dependencies);
    }

    protected function createScheduleDependency(
        int $scheduleId,
        CreateTaskDependencyRequest $request,
        int $organizationId,
        callable $finder
    ): JsonResponse {
        $schedule = $this->findScheduleOrFail($scheduleId, $finder);

        $data = $request->validated();
        $data['schedule_id'] = $schedule->id;
        $data['organization_id'] = $organizationId;
        $data['created_by_user_id'] = $request->user()->id;
        $data['is_active'] = true;
        $data['validation_status'] = 'valid';

        try {
            $dependency = TaskDependency::create($data);
            $dependency->load(['predecessorTask', 'successorTask', 'createdBy']);

            return AdminResponse::success([
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
                ],
            ], trans_message('schedule_management.dependency_created'), 201);
        } catch (CircularDependencyException $e) {
            Log::warning('[HasScheduleOperations.createScheduleDependency] Circular dependency detected', [
                'schedule_id' => $scheduleId,
                'cycle_tasks' => $e->getCycleTasks(),
                'message' => $e->getMessage(),
            ]);

            return AdminResponse::error($e->getMessage(), 422, ['cycle_tasks' => $e->getCycleTasks()]);
        } catch (ScheduleValidationException $e) {
            Log::warning('[HasScheduleOperations.createScheduleDependency] Validation failed', [
                'schedule_id' => $scheduleId,
                'errors' => $e->getErrors(),
                'message' => $e->getMessage(),
            ]);

            return AdminResponse::error($e->getMessage(), 422, $e->getErrors());
        } catch (\Throwable $e) {
            Log::error('[HasScheduleOperations.createScheduleDependency] Unexpected error', [
                'schedule_id' => $scheduleId,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return AdminResponse::error(trans_message('schedule_management.dependency_create_error'), 500);
        }
    }

    protected function updateScheduleDependency(
        int $scheduleId,
        int $dependencyId,
        UpdateTaskDependencyRequest $request,
        int $organizationId,
        callable $finder
    ): JsonResponse {
        $schedule = $this->findScheduleOrFail($scheduleId, $finder);

        $dependency = TaskDependency::query()
            ->where('id', $dependencyId)
            ->where('schedule_id', $schedule->id)
            ->first();

        if (!$dependency) {
            return AdminResponse::error(trans_message('schedule_management.dependency_not_found'), 404);
        }

        $data = $request->validated();

        try {
            $dependency->update($data);
            $schedule->update(['critical_path_calculated' => false]);
            $dependency->load(['predecessorTask', 'successorTask', 'createdBy']);

            return AdminResponse::success([
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
                    'id' => $dependency->predecessorTask->id ?? null,
                    'name' => $dependency->predecessorTask->name ?? null,
                    'planned_start_date' => $dependency->predecessorTask->planned_start_date ?? null,
                    'planned_end_date' => $dependency->predecessorTask->planned_end_date ?? null,
                ],
                'successor_task' => [
                    'id' => $dependency->successorTask->id ?? null,
                    'name' => $dependency->successorTask->name ?? null,
                    'planned_start_date' => $dependency->successorTask->planned_start_date ?? null,
                    'planned_end_date' => $dependency->successorTask->planned_end_date ?? null,
                ],
                'created_by' => [
                    'id' => $dependency->createdBy->id ?? null,
                    'name' => $dependency->createdBy->name ?? null,
                    'email' => $dependency->createdBy->email ?? null,
                ],
            ], trans_message('schedule_management.dependency_updated'));
        } catch (CircularDependencyException $e) {
            Log::warning('[HasScheduleOperations.updateScheduleDependency] Circular dependency detected', [
                'schedule_id' => $scheduleId,
                'dependency_id' => $dependencyId,
                'message' => $e->getMessage(),
            ]);

            return AdminResponse::error($e->getMessage(), 422, ['cycle_tasks' => $e->getCycleTasks()]);
        } catch (\Throwable $e) {
            Log::error('[HasScheduleOperations.updateScheduleDependency] Unexpected error', [
                'schedule_id' => $scheduleId,
                'dependency_id' => $dependencyId,
                'organization_id' => $organizationId,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return AdminResponse::error(trans_message('schedule_management.dependency_update_error'), 500);
        }
    }

    protected function deleteScheduleDependency(int $scheduleId, int $dependencyId, callable $finder): JsonResponse
    {
        $schedule = $this->findScheduleOrFail($scheduleId, $finder);

        $dependency = TaskDependency::query()
            ->where('id', $dependencyId)
            ->where('schedule_id', $schedule->id)
            ->first();

        if (!$dependency) {
            return AdminResponse::error(trans_message('schedule_management.dependency_not_found'), 404);
        }

        try {
            $dependency->delete();
            $schedule->update(['critical_path_calculated' => false]);

            return AdminResponse::success(null, trans_message('schedule_management.dependency_deleted'));
        } catch (\Throwable $e) {
            Log::error('[HasScheduleOperations.deleteScheduleDependency] Unexpected error', [
                'schedule_id' => $scheduleId,
                'dependency_id' => $dependencyId,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return AdminResponse::error(trans_message('schedule_management.dependency_delete_error'), 500);
        }
    }

    protected function getResourceConflicts(int $scheduleId, int $organizationId, callable $finder): JsonResponse
    {
        $schedule = $this->findScheduleOrFail($scheduleId, $finder);

        try {
            $schedule->load([
                'tasks' => function ($query) {
                    $query->whereHas('resources', function ($resourceQuery) {
                        $resourceQuery->where('has_conflicts', true);
                    })->with(['resources', 'assignedUser']);
                },
                'resources' => function ($query) {
                    $query->where('has_conflicts', true);
                },
            ]);

            $hasConflicts = $schedule->tasks->isNotEmpty() || $schedule->resources->isNotEmpty();

            if (!$hasConflicts) {
                return AdminResponse::success([
                    'schedule_id' => $schedule->id,
                    'schedule_name' => $schedule->name,
                    'conflicted_tasks' => [],
                    'conflicted_resources' => [],
                    'conflicts_count' => 0,
                    'has_conflicts' => false,
                    'message' => trans_message('schedule_management.resource_conflicts_none'),
                ]);
            }

            return AdminResponse::success([
                'schedule_id' => $schedule->id,
                'schedule_name' => $schedule->name,
                'conflicted_tasks' => $schedule->tasks,
                'conflicted_resources' => $schedule->resources,
                'conflicts_count' => $schedule->tasks->count() + $schedule->resources->count(),
                'has_conflicts' => true,
                'message' => trans_message('schedule_management.resource_conflicts_found'),
            ]);
        } catch (\Throwable $e) {
            Log::error('[HasScheduleOperations.getResourceConflicts] Unexpected error', [
                'schedule_id' => $scheduleId,
                'organization_id' => $organizationId,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return AdminResponse::error(trans_message('schedule_management.resource_conflicts_details_error'), 500);
        }
    }
}
