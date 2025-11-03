<?php

namespace App\Observers;

use App\Models\ScheduleTask;
use App\Exceptions\Schedule\ScheduleValidationException;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ScheduleTaskObserver
{
    /**
     * Валидация при создании задачи
     */
    public function creating(ScheduleTask $task): void
    {
        $this->validateDates($task);
        $this->calculateAndSetDuration($task);
    }

    /**
     * Валидация при обновлении задачи
     */
    public function updating(ScheduleTask $task): void
    {
        // Валидируем только измененные поля
        if ($task->isDirty(['planned_start_date', 'planned_end_date', 'actual_start_date', 'actual_end_date'])) {
            $this->validateDates($task);
            $this->calculateAndSetDuration($task);
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
            try {
                $startDate = $task->planned_start_date instanceof Carbon 
                    ? $task->planned_start_date 
                    : Carbon::parse($task->planned_start_date);
                    
                $endDate = $task->planned_end_date instanceof Carbon 
                    ? $task->planned_end_date 
                    : Carbon::parse($task->planned_end_date);
                
                if ($endDate < $startDate) {
                    $errors['planned_end_date'] = ['Дата окончания должна быть позже или равна дате начала'];
                }
            } catch (\Exception $e) {
                $errors['planned_dates'] = ['Неверный формат даты: ' . $e->getMessage()];
            }
        }

        // Фактические даты
        if ($task->actual_start_date && $task->actual_end_date) {
            try {
                $actualStartDate = $task->actual_start_date instanceof Carbon 
                    ? $task->actual_start_date 
                    : Carbon::parse($task->actual_start_date);
                    
                $actualEndDate = $task->actual_end_date instanceof Carbon 
                    ? $task->actual_end_date 
                    : Carbon::parse($task->actual_end_date);
                
                if ($actualEndDate < $actualStartDate) {
                    $errors['actual_end_date'] = ['Фактическая дата окончания должна быть позже или равна дате начала'];
                }
            } catch (\Exception $e) {
                $errors['actual_dates'] = ['Неверный формат даты: ' . $e->getMessage()];
            }
        }

        if (!empty($errors)) {
            throw new ScheduleValidationException('Ошибка валидации дат задачи', $errors);
        }
    }

    /**
     * Автоматически вычисляем и устанавливаем длительность на основе дат
     */
    protected function calculateAndSetDuration(ScheduleTask $task): void
    {
        // Вычисляем планируемую длительность
        if ($task->planned_start_date && $task->planned_end_date) {
            try {
                // Преобразуем в Carbon если нужно
                $startDate = $task->planned_start_date instanceof Carbon 
                    ? $task->planned_start_date 
                    : Carbon::parse($task->planned_start_date);
                    
                $endDate = $task->planned_end_date instanceof Carbon 
                    ? $task->planned_end_date 
                    : Carbon::parse($task->planned_end_date);
                
                $calculatedDuration = $startDate->diffInDays($endDate) + 1;
                
                // Автоматически устанавливаем вычисленную длительность
                $task->setAttribute('planned_duration_days', $calculatedDuration);
                
                Log::info('Автоматически вычислена длительность задачи', [
                    'task_id' => $task->id ?? 'new',
                    'task_name' => $task->name,
                    'calculated_duration' => $calculatedDuration,
                    'planned_start_date' => $startDate->format('Y-m-d'),
                    'planned_end_date' => $endDate->format('Y-m-d'),
                ]);
            } catch (\Exception $e) {
                Log::error('Ошибка при вычислении длительности задачи', [
                    'task_id' => $task->id ?? 'new',
                    'error' => $e->getMessage(),
                    'planned_start_date' => $task->planned_start_date,
                    'planned_end_date' => $task->planned_end_date,
                ]);
            }
        }
        
        // Вычисляем фактическую длительность если есть обе даты
        if ($task->actual_start_date && $task->actual_end_date) {
            try {
                $actualStartDate = $task->actual_start_date instanceof Carbon 
                    ? $task->actual_start_date 
                    : Carbon::parse($task->actual_start_date);
                    
                $actualEndDate = $task->actual_end_date instanceof Carbon 
                    ? $task->actual_end_date 
                    : Carbon::parse($task->actual_end_date);
                
                $actualDuration = $actualStartDate->diffInDays($actualEndDate) + 1;
                $task->setAttribute('actual_duration_days', $actualDuration);
                
                Log::info('Автоматически вычислена фактическая длительность задачи', [
                    'task_id' => $task->id ?? 'new',
                    'task_name' => $task->name,
                    'actual_duration' => $actualDuration,
                ]);
            } catch (\Exception $e) {
                Log::error('Ошибка при вычислении фактической длительности задачи', [
                    'task_id' => $task->id ?? 'new',
                    'error' => $e->getMessage(),
                    'actual_start_date' => $task->actual_start_date,
                    'actual_end_date' => $task->actual_end_date,
                ]);
            }
        }
    }
}

