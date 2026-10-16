<?php

namespace App\Observers;

use App\Models\TaskResource;
use App\Exceptions\Schedule\ScheduleValidationException;
use function trans_message;

class TaskResourceObserver
{
    /**
     * Валидация при создании ресурса
     */
    public function creating(TaskResource $resource): void
    {
        $this->validateTaskExists($resource);
        $this->validateTaskNotCompleted($resource);
        $this->validateDates($resource);
        $this->validateAllocation($resource);
    }

    /**
     * Валидация при обновлении ресурса
     */
    public function updating(TaskResource $resource): void
    {
        if ($resource->isDirty(['task_id'])) {
            $this->validateTaskNotCompleted($resource);
        }

        if ($resource->isDirty(['assignment_start_date', 'assignment_end_date'])) {
            $this->validateDates($resource);
        }

        if ($resource->isDirty(['allocation_percent'])) {
            $this->validateAllocation($resource);
        }
    }

    /**
     * Проверка существования задачи
     */
    protected function validateTaskExists(TaskResource $resource): void
    {
        if (!$resource->task) {
            throw new ScheduleValidationException(
                trans_message('schedule_management.task_resource_task_missing'),
                ['task_id' => [trans_message('schedule_management.task_resource_task_not_found')]]
            );
        }
    }

    /**
     * Проверка, что задача не завершена
     */
    protected function validateTaskNotCompleted(TaskResource $resource): void
    {
        if ($resource->task && $resource->task->status->value === 'completed') {
            throw new ScheduleValidationException(
                trans_message('schedule_management.task_resource_completed_task'),
                ['task_id' => [trans_message('schedule_management.task_resource_task_completed')]]
            );
        }
    }

    /**
     * Валидация дат назначения
     */
    protected function validateDates(TaskResource $resource): void
    {
        $errors = [];

        if ($resource->assignment_start_date && $resource->assignment_end_date) {
            if ($resource->assignment_end_date < $resource->assignment_start_date) {
                $errors['assignment_end_date'] = [trans_message('schedule_management.task_resource_end_before_start')];
            }

            // Проверка соответствия датам задачи
            if ($resource->task) {
                $taskStart = $resource->task->planned_start_date ?? $resource->task->actual_start_date;
                $taskEnd = $resource->task->planned_end_date ?? $resource->task->actual_end_date;

                if ($taskStart && $resource->assignment_start_date < $taskStart) {
                    $errors['assignment_start_date'] = [trans_message('schedule_management.task_resource_start_before_task')];
                }

                if ($taskEnd && $resource->assignment_end_date > $taskEnd) {
                    $errors['assignment_end_date'] = [trans_message('schedule_management.task_resource_end_after_task')];
                }
            }
        }

        if (!empty($errors)) {
            throw new ScheduleValidationException(trans_message('schedule_management.task_resource_dates_invalid'), $errors);
        }
    }

    /**
     * Валидация процента загрузки
     */
    protected function validateAllocation(TaskResource $resource): void
    {
        if ($resource->allocation_percent !== null) {
            if ($resource->allocation_percent < 0 || $resource->allocation_percent > 100) {
                throw new ScheduleValidationException(
                    trans_message('schedule_management.task_resource_allocation_invalid'),
                    ['allocation_percent' => [trans_message('schedule_management.task_resource_allocation_out_of_range')]]
                );
            }
        }
    }
}

