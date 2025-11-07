<?php

namespace App\BusinessModules\Features\ScheduleManagement\Services;

use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use App\Models\Estimate;
use App\Models\EstimateItem;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class EstimateSyncService
{
    public function __construct(
        private readonly EstimateScheduleImportService $importService
    ) {}

    /**
     * Синхронизировать график со сметой (обновить данные из сметы)
     * 
     * @param ProjectSchedule $schedule График
     * @param bool $force Принудительная синхронизация
     * @return array Результаты синхронизации
     * 
     * @throws \DomainException Если график не связан со сметой
     */
    public function syncScheduleWithEstimate(ProjectSchedule $schedule, bool $force = false): array
    {
        if (!$schedule->estimate_id) {
            throw new \DomainException('График не связан со сметой');
        }

        if (!$schedule->sync_with_estimate && !$force) {
            throw new \DomainException('Синхронизация со сметой отключена');
        }

        $estimate = $schedule->estimate;
        
        if (!$estimate) {
            throw new \DomainException('Смета не найдена');
        }

        return DB::transaction(function () use ($schedule, $estimate, $force) {
            $results = [
                'updated' => 0,
                'added' => 0,
                'removed' => 0,
                'conflicts' => [],
                'changes' => [],
            ];

            // Получаем все задачи графика, связанные со сметой
            $tasks = $schedule->tasks()
                ->whereNotNull('estimate_item_id')
                ->with('estimateItem')
                ->get();

            // Обновляем существующие задачи
            foreach ($tasks as $task) {
                $item = $task->estimateItem;
                
                if (!$item) {
                    // Позиция удалена из сметы
                    $results['removed']++;
                    $results['changes'][] = [
                        'type' => 'removed',
                        'task_id' => $task->id,
                        'task_name' => $task->name,
                        'message' => 'Позиция удалена из сметы',
                    ];
                    continue;
                }

                // Проверяем изменения и обновляем
                $changes = $this->updateTaskFromItem($task, $item);
                
                if (!empty($changes)) {
                    $results['updated']++;
                    $results['changes'][] = [
                        'type' => 'updated',
                        'task_id' => $task->id,
                        'task_name' => $task->name,
                        'changes' => $changes,
                    ];
                }
            }

            // Проверяем наличие новых позиций в смете
            $existingItemIds = $tasks->pluck('estimate_item_id')->filter()->toArray();
            $estimateItemIds = $estimate->items()->pluck('id')->toArray();
            $newItemIds = array_diff($estimateItemIds, $existingItemIds);

            if (count($newItemIds) > 0) {
                $results['added'] = count($newItemIds);
                $results['changes'][] = [
                    'type' => 'info',
                    'message' => "Обнаружено {$results['added']} новых позиций в смете. Используйте полный реимпорт для добавления.",
                ];
            }

            // Обновляем статус синхронизации
            $this->markScheduleAsSynced($schedule);

            \Log::info('schedule.synced_with_estimate', [
                'schedule_id' => $schedule->id,
                'estimate_id' => $estimate->id,
                'results' => $results,
            ]);

            return $results;
        });
    }

    /**
     * Синхронизировать прогресс выполнения из графика в смету
     * 
     * @param ProjectSchedule $schedule График
     * @return array Результаты синхронизации
     */
    public function syncEstimateProgress(ProjectSchedule $schedule): array
    {
        if (!$schedule->estimate_id) {
            throw new \DomainException('График не связан со сметой');
        }

        $results = [
            'updated' => 0,
            'items' => [],
        ];

        return DB::transaction(function () use ($schedule, &$results) {
            $tasks = $schedule->tasks()
                ->whereNotNull('estimate_item_id')
                ->where('progress_percent', '>', 0)
                ->with('estimateItem')
                ->get();

            foreach ($tasks as $task) {
                $item = $task->estimateItem;
                
                if (!$item) {
                    continue;
                }

                // Рассчитываем фактический объем выполненных работ
                $actualQuantity = $task->quantity 
                    ? $task->quantity * ($task->progress_percent / 100) 
                    : null;

                // Обновляем метаданные позиции сметы
                $metadata = $item->metadata ?? [];
                $metadata['progress_from_schedule'] = [
                    'schedule_id' => $schedule->id,
                    'progress_percent' => $task->progress_percent,
                    'actual_quantity' => $actualQuantity,
                    'actual_work_hours' => $task->actual_work_hours,
                    'actual_cost' => $task->actual_cost,
                    'last_synced_at' => now()->toISOString(),
                ];

                $item->update(['metadata' => $metadata]);

                $results['updated']++;
                $results['items'][] = [
                    'item_id' => $item->id,
                    'item_name' => $item->name,
                    'progress' => $task->progress_percent,
                    'actual_quantity' => $actualQuantity,
                ];
            }

            \Log::info('estimate.progress_synced_from_schedule', [
                'schedule_id' => $schedule->id,
                'estimate_id' => $schedule->estimate_id,
                'updated_items' => $results['updated'],
            ]);

            return $results;
        });
    }

    /**
     * Обнаружить конфликты между графиком и сметой
     * 
     * @param ProjectSchedule $schedule График
     * @return array Список конфликтов
     */
    public function detectConflicts(ProjectSchedule $schedule): array
    {
        if (!$schedule->estimate_id) {
            return [];
        }

        $conflicts = [];

        $tasks = $schedule->tasks()
            ->whereNotNull('estimate_item_id')
            ->with('estimateItem')
            ->get();

        foreach ($tasks as $task) {
            $item = $task->estimateItem;
            
            if (!$item) {
                $conflicts[] = [
                    'type' => 'missing_item',
                    'severity' => 'high',
                    'task_id' => $task->id,
                    'task_name' => $task->name,
                    'message' => 'Позиция сметы не найдена (возможно удалена)',
                ];
                continue;
            }

            // Проверка изменения объемов
            if ($task->quantity && $item->quantity_total && 
                abs($task->quantity - $item->quantity_total) > 0.01) {
                $conflicts[] = [
                    'type' => 'quantity_mismatch',
                    'severity' => 'medium',
                    'task_id' => $task->id,
                    'task_name' => $task->name,
                    'schedule_value' => $task->quantity,
                    'estimate_value' => $item->quantity_total,
                    'message' => 'Объем работ в графике отличается от сметы',
                ];
            }

            // Проверка изменения стоимости
            if ($task->estimated_cost && $item->total_amount &&
                abs($task->estimated_cost - $item->total_amount) > 1) {
                $conflicts[] = [
                    'type' => 'cost_mismatch',
                    'severity' => 'low',
                    'task_id' => $task->id,
                    'task_name' => $task->name,
                    'schedule_value' => $task->estimated_cost,
                    'estimate_value' => $item->total_amount,
                    'message' => 'Стоимость в графике отличается от сметы',
                ];
            }

            // Проверка изменения трудозатрат
            if ($task->labor_hours_from_estimate && $item->labor_hours &&
                abs($task->labor_hours_from_estimate - $item->labor_hours) > 0.1) {
                $conflicts[] = [
                    'type' => 'labor_hours_mismatch',
                    'severity' => 'medium',
                    'task_id' => $task->id,
                    'task_name' => $task->name,
                    'schedule_value' => $task->labor_hours_from_estimate,
                    'estimate_value' => $item->labor_hours,
                    'message' => 'Трудозатраты в графике отличаются от сметы',
                ];
            }
        }

        return $conflicts;
    }

    /**
     * Обновить задачу на основе позиции сметы
     * 
     * @param ScheduleTask $task Задача
     * @param EstimateItem $item Позиция сметы
     * @return array Список изменений
     */
    private function updateTaskFromItem(ScheduleTask $task, EstimateItem $item): array
    {
        $changes = [];
        $updates = [];

        // Проверяем и обновляем название
        if ($task->name !== $item->name) {
            $changes[] = "Название: '{$task->name}' → '{$item->name}'";
            $updates['name'] = $item->name;
        }

        // Обновляем объем
        if ($item->quantity_total && (!$task->quantity || abs($task->quantity - $item->quantity_total) > 0.01)) {
            $changes[] = "Объем: {$task->quantity} → {$item->quantity_total}";
            $updates['quantity'] = $item->quantity_total;
        }

        // Обновляем стоимость
        if ($item->total_amount && (!$task->estimated_cost || abs($task->estimated_cost - $item->total_amount) > 1)) {
            $changes[] = "Стоимость: {$task->estimated_cost} → {$item->total_amount}";
            $updates['estimated_cost'] = $item->total_amount;
            $updates['resource_cost'] = $item->total_amount;
        }

        // Обновляем трудозатраты
        if ($item->labor_hours && (!$task->labor_hours_from_estimate || abs($task->labor_hours_from_estimate - $item->labor_hours) > 0.1)) {
            $changes[] = "Трудозатраты: {$task->labor_hours_from_estimate} → {$item->labor_hours}";
            $updates['labor_hours_from_estimate'] = $item->labor_hours;
            $updates['planned_work_hours'] = $item->labor_hours;
        }

        // Применяем обновления
        if (!empty($updates)) {
            $task->update($updates);
        }

        return $changes;
    }

    /**
     * Пометить график как синхронизированный
     * 
     * @param ProjectSchedule $schedule График
     * @return void
     */
    public function markScheduleAsSynced(ProjectSchedule $schedule): void
    {
        $schedule->update([
            'last_synced_at' => now(),
            'sync_status' => 'synced',
        ]);
    }

    /**
     * Пометить график как рассинхронизированный
     * 
     * @param ProjectSchedule $schedule График
     * @return void
     */
    public function markScheduleAsOutOfSync(ProjectSchedule $schedule): void
    {
        $schedule->update([
            'sync_status' => 'out_of_sync',
        ]);
    }

    /**
     * Пометить график как имеющий конфликты
     * 
     * @param ProjectSchedule $schedule График
     * @return void
     */
    public function markScheduleAsConflict(ProjectSchedule $schedule): void
    {
        $schedule->update([
            'sync_status' => 'conflict',
        ]);
    }
}

