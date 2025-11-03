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

        // Получаем даты из атрибутов напрямую
        $startDateValue = $task->attributes['planned_start_date'] ?? $task->planned_start_date;
        $endDateValue = $task->attributes['planned_end_date'] ?? $task->planned_end_date;
        
        // Плановые даты
        if ($startDateValue && $endDateValue) {
            try {
                $startDate = $startDateValue instanceof Carbon 
                    ? $startDateValue 
                    : Carbon::parse($startDateValue);
                    
                $endDate = $endDateValue instanceof Carbon 
                    ? $endDateValue 
                    : Carbon::parse($endDateValue);
                
                if ($endDate < $startDate) {
                    $errors['planned_end_date'] = ['Дата окончания должна быть позже или равна дате начала'];
                }
            } catch (\Exception $e) {
                $errors['planned_dates'] = ['Неверный формат даты: ' . $e->getMessage()];
            }
        }

        // Получаем фактические даты из атрибутов напрямую
        $actualStartValue = $task->attributes['actual_start_date'] ?? $task->actual_start_date;
        $actualEndValue = $task->attributes['actual_end_date'] ?? $task->actual_end_date;
        
        // Фактические даты
        if ($actualStartValue && $actualEndValue) {
            try {
                $actualStartDate = $actualStartValue instanceof Carbon 
                    ? $actualStartValue 
                    : Carbon::parse($actualStartValue);
                    
                $actualEndDate = $actualEndValue instanceof Carbon 
                    ? $actualEndValue 
                    : Carbon::parse($actualEndValue);
                
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
        // Получаем даты из атрибутов напрямую
        $startDateValue = $task->attributes['planned_start_date'] ?? $task->planned_start_date;
        $endDateValue = $task->attributes['planned_end_date'] ?? $task->planned_end_date;
        
        // Вычисляем планируемую длительность
        if ($startDateValue && $endDateValue) {
            try {
                // Преобразуем в Carbon если нужно
                $startDate = $startDateValue instanceof Carbon 
                    ? $startDateValue 
                    : Carbon::parse($startDateValue);
                    
                $endDate = $endDateValue instanceof Carbon 
                    ? $endDateValue 
                    : Carbon::parse($endDateValue);
                
                $calculatedDuration = $startDate->diffInDays($endDate) + 1;
                
                // Автоматически устанавливаем вычисленную длительность
                $task->planned_duration_days = $calculatedDuration;
                
                Log::info('Автоматически вычислена длительность задачи', [
                    'task_id' => $task->id ?? 'new',
                    'task_name' => $task->name ?? 'unknown',
                    'calculated_duration' => $calculatedDuration,
                    'planned_start_date' => $startDate->format('Y-m-d'),
                    'planned_end_date' => $endDate->format('Y-m-d'),
                ]);
            } catch (\Exception $e) {
                Log::error('Ошибка при вычислении длительности задачи', [
                    'task_id' => $task->id ?? 'new',
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'planned_start_date' => $startDateValue,
                    'planned_end_date' => $endDateValue,
                ]);
                // Не бросаем исключение - позволяем создаться задаче
            }
        }
        
        // Получаем фактические даты из атрибутов напрямую
        $actualStartValue = $task->attributes['actual_start_date'] ?? $task->actual_start_date;
        $actualEndValue = $task->attributes['actual_end_date'] ?? $task->actual_end_date;
        
        // Вычисляем фактическую длительность если есть обе даты
        if ($actualStartValue && $actualEndValue) {
            try {
                $actualStartDate = $actualStartValue instanceof Carbon 
                    ? $actualStartValue 
                    : Carbon::parse($actualStartValue);
                    
                $actualEndDate = $actualEndValue instanceof Carbon 
                    ? $actualEndValue 
                    : Carbon::parse($actualEndValue);
                
                $actualDuration = $actualStartDate->diffInDays($actualEndDate) + 1;
                $task->actual_duration_days = $actualDuration;
                
                Log::info('Автоматически вычислена фактическая длительность задачи', [
                    'task_id' => $task->id ?? 'new',
                    'task_name' => $task->name ?? 'unknown',
                    'actual_duration' => $actualDuration,
                ]);
            } catch (\Exception $e) {
                Log::error('Ошибка при вычислении фактической длительности задачи', [
                    'task_id' => $task->id ?? 'new',
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'actual_start_date' => $actualStartValue,
                    'actual_end_date' => $actualEndValue,
                ]);
                // Не бросаем исключение - позволяем создаться задаче
            }
        }
    }
}

