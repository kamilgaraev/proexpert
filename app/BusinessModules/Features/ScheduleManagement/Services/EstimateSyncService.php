<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Services;

use App\Enums\Schedule\TaskStatusEnum;
use App\Enums\Schedule\TaskTypeEnum;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateSection;
use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EstimateSyncService
{
    public function __construct(
        private readonly EstimateScheduleImportService $importService
    ) {}

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

        return DB::transaction(function () use ($schedule, $estimate): array {
            $results = [
                'updated' => 0,
                'added' => 0,
                'removed' => 0,
                'conflicts' => [],
                'changes' => [],
            ];

            $tasks = $schedule->tasks()
                ->whereNotNull('estimate_item_id')
                ->with('estimateItem')
                ->get();

            foreach ($tasks as $task) {
                $item = $task->estimateItem;

                if (!$item || $item->estimate_id !== $estimate->id || !$item->isWork()) {
                    $this->removeObsoleteTask($task, $results);
                    continue;
                }

                $changes = $this->updateTaskFromItem($task, $item);

                if ($changes !== []) {
                    $results['updated']++;
                    $results['changes'][] = [
                        'type' => 'updated',
                        'task_id' => $task->id,
                        'task_name' => $task->name,
                        'changes' => $changes,
                    ];
                }
            }

            $existingItemIds = $schedule->tasks()
                ->whereNotNull('estimate_item_id')
                ->pluck('estimate_item_id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            $estimateItemIds = $estimate->items()
                ->works()
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            $newItemIds = array_values(array_diff($estimateItemIds, $existingItemIds));

            if ($newItemIds !== []) {
                $this->appendNewEstimateItems($schedule, $estimate, $newItemIds, $results);
            }

            $this->removeEmptySectionTasks($schedule, $results);
            $this->importService->recalculateScheduleDates($schedule);

            $schedule->update([
                'total_estimated_cost' => $estimate->total_amount,
                'critical_path_calculated' => false,
            ]);

            if ($results['conflicts'] === []) {
                $this->markScheduleAsSynced($schedule);
            } else {
                $this->markScheduleAsConflict($schedule);
            }

            Log::info('schedule.synced_with_estimate', [
                'schedule_id' => $schedule->id,
                'estimate_id' => $estimate->id,
                'results' => $results,
            ]);

            return $results;
        });
    }

    public function syncEstimateProgress(ProjectSchedule $schedule): array
    {
        if (!$schedule->estimate_id) {
            throw new \DomainException('График не связан со сметой');
        }

        $results = [
            'updated' => 0,
            'items' => [],
        ];

        return DB::transaction(function () use ($schedule, &$results): array {
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

                $actualQuantity = $task->quantity
                    ? (float) $task->quantity * ((float) $task->progress_percent / 100)
                    : null;

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

            Log::info('estimate.progress_synced_from_schedule', [
                'schedule_id' => $schedule->id,
                'estimate_id' => $schedule->estimate_id,
                'updated_items' => $results['updated'],
            ]);

            return $results;
        });
    }

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
                    'message' => 'Позиция сметы не найдена',
                ];
                continue;
            }

            $quantity = $this->itemQuantity($item);
            if ($this->numericValuesDiffer($task->quantity, $quantity, 0.01)) {
                $conflicts[] = [
                    'type' => 'quantity_mismatch',
                    'severity' => 'medium',
                    'task_id' => $task->id,
                    'task_name' => $task->name,
                    'schedule_value' => $task->quantity,
                    'estimate_value' => $quantity,
                    'message' => 'Объем работ в графике отличается от сметы',
                ];
            }

            if ($this->numericValuesDiffer($task->estimated_cost, $item->total_amount, 1.0)) {
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

            if ($this->numericValuesDiffer($task->labor_hours_from_estimate, $item->labor_hours, 0.1)) {
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

    public function markScheduleAsSynced(ProjectSchedule $schedule): void
    {
        $schedule->update([
            'last_synced_at' => now(),
            'sync_status' => 'synced',
        ]);
    }

    public function markScheduleAsOutOfSync(ProjectSchedule $schedule): void
    {
        $schedule->update([
            'sync_status' => 'out_of_sync',
        ]);
    }

    public function markScheduleAsConflict(ProjectSchedule $schedule): void
    {
        $schedule->update([
            'sync_status' => 'conflict',
        ]);
    }

    private function updateTaskFromItem(ScheduleTask $task, EstimateItem $item): array
    {
        $changes = [];
        $updates = [];

        if ($task->name !== $item->name) {
            $changes[] = "name: {$task->name} -> {$item->name}";
            $updates['name'] = $item->name;
        }

        $description = $item->description ?? $item->justification;
        if ($task->description !== $description) {
            $changes[] = 'description';
            $updates['description'] = $description;
        }

        $quantity = $this->itemQuantity($item);
        if ($this->numericValuesDiffer($task->quantity, $quantity, 0.01)) {
            $changes[] = "quantity: {$task->quantity} -> {$quantity}";
            $updates['quantity'] = $quantity;
        }

        if ($this->numericValuesDiffer($task->estimated_cost, $item->total_amount, 1.0)) {
            $changes[] = "cost: {$task->estimated_cost} -> {$item->total_amount}";
            $updates['estimated_cost'] = $item->total_amount;
            $updates['resource_cost'] = $item->total_amount;
        }

        if ($this->numericValuesDiffer($task->labor_hours_from_estimate, $item->labor_hours, 0.1)) {
            $changes[] = "labor_hours: {$task->labor_hours_from_estimate} -> {$item->labor_hours}";
            $updates['labor_hours_from_estimate'] = $item->labor_hours;
            $updates['planned_work_hours'] = $item->labor_hours ?? 0;
        }

        if ($this->nullableIntChanged($task->work_type_id, $item->work_type_id)) {
            $changes[] = 'work_type_id';
            $updates['work_type_id'] = $item->work_type_id;
        }

        if ($this->nullableIntChanged($task->measurement_unit_id, $item->measurement_unit_id)) {
            $changes[] = 'measurement_unit_id';
            $updates['measurement_unit_id'] = $item->measurement_unit_id;
        }

        if ($updates !== []) {
            $task->update($updates);
        }

        return $changes;
    }

    private function appendNewEstimateItems(
        ProjectSchedule $schedule,
        Estimate $estimate,
        array $newItemIds,
        array &$results
    ): void {
        $items = EstimateItem::query()
            ->where('estimate_id', $estimate->id)
            ->whereIn('id', $newItemIds)
            ->works()
            ->with(['section', 'workType', 'measurementUnit'])
            ->get();

        $options = $this->buildImportOptions($schedule);
        $startDate = $this->nextStartDate($schedule);
        $sortOrder = (int) ($schedule->tasks()->max('sort_order') ?? 0) + 1;

        foreach ($items as $item) {
            if (!$item->section instanceof EstimateSection) {
                $this->addConflict($results, 'missing_section', 'high', null, $item->name, 'У позиции сметы нет раздела');
                continue;
            }

            $sectionTask = $this->findOrCreateSectionTask(
                $schedule,
                $item->section,
                $startDate->copy(),
                $sortOrder,
                $options,
                $results
            );

            $taskStartDate = $this->nextTaskStartDate($sectionTask, $startDate);
            $task = $this->importService->createTaskFromItem(
                $schedule,
                $item,
                $item->section,
                $sectionTask,
                $taskStartDate,
                $sortOrder++,
                $options
            );

            $results['added']++;
            $results['changes'][] = [
                'type' => 'added',
                'task_id' => $task->id,
                'task_name' => $task->name,
                'estimate_item_id' => $item->id,
            ];

            $this->refreshSectionTaskDates($sectionTask);
            $startDate = Carbon::parse($task->planned_end_date)->addDay();
        }
    }

    private function findOrCreateSectionTask(
        ProjectSchedule $schedule,
        EstimateSection $section,
        Carbon $startDate,
        int &$sortOrder,
        array $options,
        array &$results
    ): ScheduleTask {
        $existing = $schedule->tasks()
            ->whereNull('parent_task_id')
            ->where('estimate_section_id', $section->id)
            ->where('task_type', TaskTypeEnum::SUMMARY->value)
            ->first();

        if ($existing) {
            return $existing;
        }

        $sectionTask = $this->importService->createTaskFromSection(
            $schedule,
            $section,
            $startDate,
            $sortOrder++,
            $options
        );

        $results['changes'][] = [
            'type' => 'added_section',
            'task_id' => $sectionTask->id,
            'task_name' => $sectionTask->name,
            'estimate_section_id' => $section->id,
        ];

        return $sectionTask;
    }

    private function removeObsoleteTask(ScheduleTask $task, array &$results): void
    {
        if (!$this->canDeleteObsoleteTask($task)) {
            $this->addConflict(
                $results,
                'removed_item_has_progress',
                'high',
                $task->id,
                $task->name,
                'Связанная позиция сметы удалена, но задача уже имеет факт или статус'
            );
            return;
        }

        $parentTask = $task->parentTask;
        $taskName = $task->name;
        $taskId = $task->id;

        $task->delete();

        $results['removed']++;
        $results['changes'][] = [
            'type' => 'removed',
            'task_id' => $taskId,
            'task_name' => $taskName,
        ];

        if ($parentTask instanceof ScheduleTask) {
            $this->refreshSectionTaskDates($parentTask);
        }
    }

    private function removeEmptySectionTasks(ProjectSchedule $schedule, array &$results): void
    {
        $sectionTasks = $schedule->tasks()
            ->whereNull('parent_task_id')
            ->whereNull('estimate_item_id')
            ->whereNotNull('estimate_section_id')
            ->where('task_type', TaskTypeEnum::SUMMARY->value)
            ->get();

        foreach ($sectionTasks as $sectionTask) {
            if ($sectionTask->childTasks()->exists() || !$this->canDeleteObsoleteTask($sectionTask)) {
                continue;
            }

            $taskName = $sectionTask->name;
            $taskId = $sectionTask->id;

            $sectionTask->delete();

            $results['removed']++;
            $results['changes'][] = [
                'type' => 'removed_section',
                'task_id' => $taskId,
                'task_name' => $taskName,
            ];
        }
    }

    private function canDeleteObsoleteTask(ScheduleTask $task): bool
    {
        $status = $task->status instanceof TaskStatusEnum
            ? $task->status
            : TaskStatusEnum::tryFrom((string) $task->status);

        if ($status !== null && $status !== TaskStatusEnum::NOT_STARTED) {
            return false;
        }

        if ((float) ($task->progress_percent ?? 0) > 0) {
            return false;
        }

        if ((float) ($task->completed_quantity ?? 0) > 0) {
            return false;
        }

        return !$task->completedWorks()->exists();
    }

    private function refreshSectionTaskDates(ScheduleTask $sectionTask): void
    {
        $freshTask = $sectionTask->fresh('childTasks');

        if ($freshTask instanceof ScheduleTask) {
            $this->importService->updateSectionTaskDates($freshTask);
        }
    }

    private function nextStartDate(ProjectSchedule $schedule): Carbon
    {
        $latestTaskEndDate = $schedule->tasks()->max('planned_end_date');

        if ($latestTaskEndDate) {
            return Carbon::parse($latestTaskEndDate)->addDay();
        }

        if ($schedule->planned_start_date) {
            return Carbon::parse($schedule->planned_start_date);
        }

        return Carbon::now();
    }

    private function nextTaskStartDate(ScheduleTask $sectionTask, Carbon $defaultStartDate): Carbon
    {
        $latestChildEndDate = $sectionTask->childTasks()->max('planned_end_date');

        if ($latestChildEndDate) {
            return Carbon::parse($latestChildEndDate)->addDay();
        }

        return $defaultStartDate->copy();
    }

    private function buildImportOptions(ProjectSchedule $schedule): array
    {
        $settings = $schedule->calculation_settings ?? [];
        $workingDaysPerWeek = (int) ($settings['working_days_per_week'] ?? 5);

        return [
            'workers_count' => (int) ($settings['workers_count'] ?? 1),
            'hours_per_day' => (int) ($settings['working_hours_per_day'] ?? $settings['hours_per_day'] ?? 8),
            'include_weekends' => (bool) ($settings['include_weekends'] ?? $workingDaysPerWeek >= 7),
            'auto_calculate_dates' => true,
        ];
    }

    private function itemQuantity(EstimateItem $item): mixed
    {
        return $item->quantity_total ?? $item->quantity;
    }

    private function numericValuesDiffer(mixed $current, mixed $next, float $tolerance): bool
    {
        if ($current === null || $next === null) {
            return $current !== $next;
        }

        return abs((float) $current - (float) $next) > $tolerance;
    }

    private function nullableIntChanged(mixed $current, mixed $next): bool
    {
        $currentValue = $current === null ? null : (int) $current;
        $nextValue = $next === null ? null : (int) $next;

        return $currentValue !== $nextValue;
    }

    private function addConflict(
        array &$results,
        string $type,
        string $severity,
        ?int $taskId,
        string $taskName,
        string $message
    ): void {
        $results['conflicts'][] = [
            'type' => $type,
            'severity' => $severity,
            'task_id' => $taskId,
            'task_name' => $taskName,
            'message' => $message,
        ];
    }
}
