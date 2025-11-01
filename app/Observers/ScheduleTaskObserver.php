<?php

namespace App\Observers;

use App\Models\ScheduleTask;
use App\Exceptions\Schedule\ScheduleValidationException;
use Illuminate\Support\Facades\Log;

class ScheduleTaskObserver
{
    /**
     * Валидация при создании задачи
     */
    public function creating(ScheduleTask $task): void
    {
        $this->validateDates($task);
        $this->validateDatesRelation($task);
    }

    /**
     * Валидация при обновлении задачи
     */
    public function updating(ScheduleTask $task): void
    {
        // Валидируем только измененные поля
        if ($task->isDirty(['planned_start_date', 'planned_end_date', 'actual_start_date', 'actual_end_date'])) {
            $this->validateDates($task);
            $this->validateDatesRelation($task);
        }

        // Проверка, что задача не завершается раньше начала
        if ($task->isDirty('actual_end_date') && $task->actual_end_date && $task->actual_start_date) {
            if ($task->actual_end_date < $task->actual_start_date) {
                throw new ScheduleValidationException(
                    'Фактическая дата окончания не может быть раньше даты начала',
                    ['actual_end_date' => ['Дата окончания должна быть позже или равна дате начала']]
                );
            }
        }
    }

    /**
     * Валидация дат задачи
     */
    protected function validateDates(ScheduleTask $task): void
    {
        $errors = [];

        // Плановые даты
        if ($task->planned_start_date && $task->planned_end_date) {
            if ($task->planned_end_date < $task->planned_start_date) {
                $errors['planned_end_date'] = ['Дата окончания должна быть позже или равна дате начала'];
            }
        }

        // Фактические даты
        if ($task->actual_start_date && $task->actual_end_date) {
            if ($task->actual_end_date < $task->actual_start_date) {
                $errors['actual_end_date'] = ['Фактическая дата окончания должна быть позже или равна дате начала'];
            }
        }

        if (!empty($errors)) {
            throw new ScheduleValidationException('Ошибка валидации дат задачи', $errors);
        }
    }

    /**
     * Валидация соответствия дат и длительности
     */
    protected function validateDatesRelation(ScheduleTask $task): void
    {
        if ($task->planned_start_date && $task->planned_end_date && $task->planned_duration_days) {
            $calculatedDuration = $task->planned_start_date->diffInDays($task->planned_end_date) + 1;
            
            if (abs($calculatedDuration - $task->planned_duration_days) > 1) {
                Log::warning('Несоответствие длительности и дат задачи', [
                    'task_id' => $task->id,
                    'calculated_duration' => $calculatedDuration,
                    'planned_duration_days' => $task->planned_duration_days,
                ]);
            }
        }
    }
}

