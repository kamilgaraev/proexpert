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
        Log::info('[ScheduleTaskObserver] creating event triggered', [
            'task_name' => $task->name ?? 'unknown',
            'planned_start_date' => $task->attributes['planned_start_date'] ?? null,
            'planned_end_date' => $task->attributes['planned_end_date'] ?? null,
            'attributes' => $task->attributes,
        ]);
        
        try {
            $this->validateDates($task);
            Log::info('[ScheduleTaskObserver] Валидация прошла успешно');
        } catch (\Exception $e) {
            Log::error('[ScheduleTaskObserver] Ошибка валидации', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
        
        // Вычисляем длительность ПЕРЕД сохранением
        $this->calculateDuration($task);

        // Устанавливаем sort_order если он не задан
        $this->setSortOrder($task);
    }

    /**
     * Установить порядковый номер задачи
     */
    protected function setSortOrder(ScheduleTask $task): void
    {
        if ($task->sort_order > 0) {
            return;
        }

        $maxSortOrder = ScheduleTask::where('schedule_id', $task->schedule_id)
            ->where('parent_task_id', $task->parent_task_id)
            ->max('sort_order');

        $task->sort_order = ($maxSortOrder ?? 0) + 1;
        
        Log::info('[ScheduleTaskObserver] sort_order set', [
            'task_id' => $task->id,
            'sort_order' => $task->sort_order,
            'parent_task_id' => $task->parent_task_id
        ]);
    }

    /**
     * Валидация при обновлении задачи
     */
    public function updating(ScheduleTask $task): void
    {
        // Валидируем только измененные поля
        if ($task->isDirty(['planned_start_date', 'planned_end_date', 'actual_start_date', 'actual_end_date'])) {
            $this->validateDates($task);
            // Пересчитываем длительность при изменении дат
            $this->calculateDuration($task);
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
     * Обработка после обновления задачи
     */
    public function updated(ScheduleTask $task): void
    {
        if ($task->wasChanged(['planned_start_date', 'planned_end_date'])) {
            $service = app(\App\Services\Schedule\AutoSchedulingService::class);
            
            // 1. Обновляем родителей (вверх)
            $service->syncParentDates($task);
            
            // 2. Обновляем последователей (вниз)
            $service->applyCascadeUpdates($task);
        }
    }

    /**
     * Обработка после создания задачи
     */
    public function created(ScheduleTask $task): void
    {
        $service = app(\App\Services\Schedule\AutoSchedulingService::class);
        $service->syncParentDates($task);
    }

    /**
     * Обработка после удаления задачи
     */
    public function deleted(ScheduleTask $task): void
    {
        if ($task->parent_task_id) {
            // Пытаемся получить родителя (даже если он мягко удален, но тут нам нужен живой)
            $parent = ScheduleTask::find($task->parent_task_id);
            if ($parent) {
                $service = app(\App\Services\Schedule\AutoSchedulingService::class);
                $service->syncParentDates($parent); // Вызываем для родителя, так как он "условный ребенок" для уровня выше
            }
        }
    }

    /**
     * Валидация дат задачи
     */
    protected function validateDates(ScheduleTask $task): void
    {
        Log::info('[ScheduleTaskObserver] validateDates started');
        
        $errors = [];

        // Получаем даты из атрибутов напрямую
        $startDateValue = $task->attributes['planned_start_date'] ?? $task->planned_start_date;
        $endDateValue = $task->attributes['planned_end_date'] ?? $task->planned_end_date;
        
        Log::info('[ScheduleTaskObserver] Плановые даты', [
            'start' => $startDateValue,
            'end' => $endDateValue,
        ]);
        
        // Плановые даты
        if ($startDateValue && $endDateValue) {
            try {
                Log::info('[ScheduleTaskObserver] Парсинг плановых дат');
                
                $startDate = $startDateValue instanceof Carbon 
                    ? $startDateValue 
                    : Carbon::parse($startDateValue);
                    
                $endDate = $endDateValue instanceof Carbon 
                    ? $endDateValue 
                    : Carbon::parse($endDateValue);
                
                Log::info('[ScheduleTaskObserver] Даты распарсены', [
                    'start' => $startDate->format('Y-m-d'),
                    'end' => $endDate->format('Y-m-d'),
                ]);
                
                if ($endDate < $startDate) {
                    $errors['planned_end_date'] = ['Дата окончания должна быть позже или равна дате начала'];
                    Log::warning('[ScheduleTaskObserver] Дата окончания раньше даты начала');
                }
            } catch (\Exception $e) {
                Log::error('[ScheduleTaskObserver] Ошибка парсинга плановых дат', [
                    'error' => $e->getMessage(),
                    'start' => $startDateValue,
                    'end' => $endDateValue,
                ]);
                $errors['planned_dates'] = ['Неверный формат даты: ' . $e->getMessage()];
            }
        }

        // Получаем фактические даты из атрибутов напрямую
        $actualStartValue = $task->attributes['actual_start_date'] ?? $task->actual_start_date;
        $actualEndValue = $task->attributes['actual_end_date'] ?? $task->actual_end_date;
        
        // Фактические даты
        if ($actualStartValue && $actualEndValue) {
            try {
                Log::info('[ScheduleTaskObserver] Парсинг фактических дат');
                
                $actualStartDate = $actualStartValue instanceof Carbon 
                    ? $actualStartValue 
                    : Carbon::parse($actualStartValue);
                    
                $actualEndDate = $actualEndValue instanceof Carbon 
                    ? $actualEndValue 
                    : Carbon::parse($actualEndValue);
                
                if ($actualEndDate < $actualStartDate) {
                    $errors['actual_end_date'] = ['Фактическая дата окончания должна быть позже или равна дате начала'];
                    Log::warning('[ScheduleTaskObserver] Фактическая дата окончания раньше даты начала');
                }
            } catch (\Exception $e) {
                Log::error('[ScheduleTaskObserver] Ошибка парсинга фактических дат', [
                    'error' => $e->getMessage(),
                ]);
                $errors['actual_dates'] = ['Неверный формат даты: ' . $e->getMessage()];
            }
        }

        if (!empty($errors)) {
            Log::error('[ScheduleTaskObserver] Ошибки валидации', [
                'errors' => $errors,
            ]);
            throw new ScheduleValidationException('Ошибка валидации дат задачи', $errors);
        }
        
        Log::info('[ScheduleTaskObserver] validateDates completed successfully');
    }

    /**
     * Вычислить длительность задачи на основе дат
     */
    protected function calculateDuration(ScheduleTask $task): void
    {
        // Плановая длительность
        $startDateValue = $task->attributes['planned_start_date'] ?? $task->planned_start_date;
        $endDateValue = $task->attributes['planned_end_date'] ?? $task->planned_end_date;
        
        if ($startDateValue && $endDateValue) {
            try {
                $startDate = $startDateValue instanceof Carbon 
                    ? $startDateValue 
                    : Carbon::parse($startDateValue);
                    
                $endDate = $endDateValue instanceof Carbon 
                    ? $endDateValue 
                    : Carbon::parse($endDateValue);
                
                $duration = $startDate->diffInDays($endDate) + 1;
                $task->setAttribute('planned_duration_days', $duration);
                
                Log::info('[ScheduleTaskObserver] Плановая длительность вычислена', [
                    'planned_duration_days' => $duration,
                    'start' => $startDate->format('Y-m-d'),
                    'end' => $endDate->format('Y-m-d'),
                ]);
            } catch (\Exception $e) {
                Log::warning('[ScheduleTaskObserver] Не удалось вычислить плановую длительность', [
                    'error' => $e->getMessage(),
                ]);
                // Если не удалось вычислить, устанавливаем 1 день по умолчанию
                $task->setAttribute('planned_duration_days', 1);
            }
        } else {
            // Если даты не указаны, устанавливаем 1 день по умолчанию
            $task->setAttribute('planned_duration_days', 1);
        }
        
        // Фактическая длительность
        $actualStartValue = $task->attributes['actual_start_date'] ?? $task->actual_start_date;
        $actualEndValue = $task->attributes['actual_end_date'] ?? $task->actual_end_date;
        
        if ($actualStartValue && $actualEndValue) {
            try {
                $actualStartDate = $actualStartValue instanceof Carbon 
                    ? $actualStartValue 
                    : Carbon::parse($actualStartValue);
                    
                $actualEndDate = $actualEndValue instanceof Carbon 
                    ? $actualEndValue 
                    : Carbon::parse($actualEndValue);
                
                $actualDuration = $actualStartDate->diffInDays($actualEndDate) + 1;
                $task->setAttribute('actual_duration_days', $actualDuration);
                
                Log::info('[ScheduleTaskObserver] Фактическая длительность вычислена', [
                    'actual_duration_days' => $actualDuration,
                ]);
            } catch (\Exception $e) {
                Log::warning('[ScheduleTaskObserver] Не удалось вычислить фактическую длительность', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

}

