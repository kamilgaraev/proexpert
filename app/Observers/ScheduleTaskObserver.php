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
        // Длительность вычисляется автоматически через computed property в модели
    }

    /**
     * Валидация при обновлении задачи
     */
    public function updating(ScheduleTask $task): void
    {
        // Валидируем только измененные поля
        if ($task->isDirty(['planned_start_date', 'planned_end_date', 'actual_start_date', 'actual_end_date'])) {
            $this->validateDates($task);
            // Длительность вычисляется автоматически через computed property в модели
        }

        // Проверка, что задача не завершается раньше начала
        if ($task->isDirty('actual_end_date') && $task->actual_end_date && $task->actual_start_date) {
            try {
                $actualEndDate = $task->actual_end_date instanceof Carbon 
                    ? $task->actual_end_date 
                    : Carbon::parse($task->actual_end_date);
                    
                $actualStartDate = $task->actual_start_date instanceof Carbon 
                    ? $task->actual_start_date 
                    : Carbon::parse($task->actual_start_date);
                    
                if ($actualEndDate < $actualStartDate) {
                    throw new ScheduleValidationException(
                        'Фактическая дата окончания не может быть раньше даты начала',
                        ['actual_end_date' => ['Дата окончания должна быть позже или равна дате начала']]
                    );
                }
            } catch (ScheduleValidationException $e) {
                throw $e;
            } catch (\Exception $e) {
                // Игнорируем ошибки парсинга - они будут обработаны в validateDates
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

}

