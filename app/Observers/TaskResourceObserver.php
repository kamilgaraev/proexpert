<?php

namespace App\Observers;

use App\Models\TaskResource;
use App\Exceptions\Schedule\ScheduleValidationException;
use Illuminate\Support\Facades\Log;

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
                'Задача для назначения ресурса не найдена',
                ['task_id' => ['Задача не найдена']]
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
                'Нельзя назначать ресурсы на завершенную задачу',
                ['task_id' => ['Задача уже завершена']]
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
                $errors['assignment_end_date'] = ['Дата окончания назначения должна быть позже или равна дате начала'];
            }

            // Проверка соответствия датам задачи
            if ($resource->task) {
                $taskStart = $resource->task->planned_start_date ?? $resource->task->actual_start_date;
                $taskEnd = $resource->task->planned_end_date ?? $resource->task->actual_end_date;

                if ($taskStart && $resource->assignment_start_date < $taskStart) {
                    $errors['assignment_start_date'] = ['Дата начала назначения не может быть раньше даты начала задачи'];
                }

                if ($taskEnd && $resource->assignment_end_date > $taskEnd) {
                    $errors['assignment_end_date'] = ['Дата окончания назначения не может быть позже даты окончания задачи'];
                }
            }
        }

        if (!empty($errors)) {
            throw new ScheduleValidationException('Ошибка валидации дат назначения ресурса', $errors);
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
                    'Процент загрузки ресурса должен быть от 0 до 100',
                    ['allocation_percent' => ['Процент загрузки должен быть в диапазоне от 0 до 100']]
                );
            }
        }
    }
}

