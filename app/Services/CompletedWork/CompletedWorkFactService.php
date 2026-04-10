<?php

declare(strict_types=1);

namespace App\Services\CompletedWork;

use App\Enums\ConstructionJournal\JournalEntryStatusEnum;
use App\Models\CompletedWork;
use App\Models\ConstructionJournalEntry;
use App\Models\JournalWorkVolume;
use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use App\Services\Schedule\ScheduleTaskCompletedWorkService;
use App\Services\Schedule\ScheduleTaskService;
use Illuminate\Support\Facades\DB;

class CompletedWorkFactService
{
    public function __construct(
        private readonly ScheduleTaskCompletedWorkService $scheduleTaskCompletedWorkService,
        private readonly ScheduleTaskService $scheduleTaskService,
    ) {
    }

    public function syncFromJournalEntry(ConstructionJournalEntry $entry): void
    {
        $entry->loadMissing([
            'journal',
            'scheduleTask.estimateItem.contractLinks.contract',
            'workVolumes.estimateItem.contractLinks.contract',
            'workVolumes.workType',
        ]);

        DB::transaction(function () use ($entry): void {
            $existingWorks = $entry->completedWorks()->orderBy('id')->get()->values();
            $workVolumes = $entry->workVolumes->values();
            $syncedTaskIds = collect();

            foreach ($workVolumes as $index => $volume) {
                $task = $this->resolveTaskForEntry($entry, $volume);
                $payload = $this->buildPayloadFromJournalVolume($entry, $volume, $task);

                /** @var CompletedWork $completedWork */
                $completedWork = $existingWorks->get($index) ?? new CompletedWork();
                $completedWork->fill($payload);
                $completedWork->save();

                if ($completedWork->schedule_task_id) {
                    $syncedTaskIds->push((int) $completedWork->schedule_task_id);
                }
            }

            if ($existingWorks->count() > $workVolumes->count()) {
                $existingWorks
                    ->slice($workVolumes->count())
                    ->each(function (CompletedWork $completedWork) use ($syncedTaskIds): void {
                        if ($completedWork->schedule_task_id) {
                            $syncedTaskIds->push((int) $completedWork->schedule_task_id);
                        }

                        $completedWork->delete();
                    });
            }

            $syncedTaskIds
                ->filter()
                ->unique()
                ->each(fn (int $taskId) => $this->syncTaskById($taskId));
        });
    }

    public function deleteJournalEntryFacts(ConstructionJournalEntry $entry): void
    {
        $taskIds = $entry->completedWorks()
            ->whereNotNull('schedule_task_id')
            ->pluck('schedule_task_id')
            ->map(fn ($id) => (int) $id)
            ->unique();

        DB::transaction(function () use ($entry, $taskIds): void {
            $entry->completedWorks()->delete();

            $taskIds->each(fn (int $taskId) => $this->syncTaskById($taskId));
        });
    }

    public function attachToTask(CompletedWork $completedWork, ScheduleTask $task): CompletedWork
    {
        $completedWork->forceFill([
            'schedule_task_id' => $task->id,
            'estimate_item_id' => $completedWork->estimate_item_id ?? $task->estimate_item_id,
            'project_id' => $task->schedule->project_id,
            'organization_id' => $task->organization_id,
            'planning_status' => CompletedWork::PLANNING_PLANNED,
        ]);

        if (!$completedWork->work_type_id && $task->work_type_id) {
            $completedWork->work_type_id = $task->work_type_id;
        }

        $completedWork->save();
        $this->syncTaskById($task->id);

        return $completedWork->fresh([
            'scheduleTask.schedule',
            'estimateItem.measurementUnit',
            'journalEntry',
            'workType',
            'user',
            'project',
            'contract.contractor',
            'contractor',
            'materials.measurementUnit',
        ]);
    }

    public function createTaskFromWork(CompletedWork $completedWork, ProjectSchedule $schedule, int $userId): ScheduleTask
    {
        $completedWork->loadMissing(['estimateItem.measurementUnit', 'workType']);

        $quantity = (float) ($completedWork->quantity ?? $completedWork->completed_quantity ?? 0);
        $completedQuantity = (float) ($completedWork->completed_quantity ?? $quantity);
        $progressPercent = $quantity > 0 ? min(100, round(($completedQuantity / $quantity) * 100, 2)) : 0;
        $sortOrder = $this->scheduleTaskService->getNextSortOrder($schedule->id);

        $task = ScheduleTask::create([
            'schedule_id' => $schedule->id,
            'organization_id' => $schedule->organization_id,
            'estimate_item_id' => $completedWork->estimate_item_id,
            'work_type_id' => $completedWork->work_type_id,
            'created_by_user_id' => $userId,
            'name' => $this->resolveTaskName($completedWork),
            'description' => $completedWork->notes,
            'task_type' => 'task',
            'planned_start_date' => $completedWork->completion_date,
            'planned_end_date' => $completedWork->completion_date,
            'quantity' => $quantity > 0 ? $quantity : null,
            'completed_quantity' => $completedQuantity > 0 ? $completedQuantity : null,
            'measurement_unit_id' => $completedWork->estimateItem?->measurement_unit_id,
            'progress_percent' => $progressPercent,
            'status' => $progressPercent >= 100 ? 'completed' : ($progressPercent > 0 ? 'in_progress' : 'not_started'),
            'priority' => 'normal',
            'level' => 0,
            'sort_order' => $sortOrder,
        ]);

        $this->attachToTask($completedWork, $task->load('schedule'));

        return $task->fresh([
            'schedule',
            'assignedUser',
            'workType',
            'measurementUnit',
            'estimateItem',
        ]);
    }

    public function resolveTaskForEstimateItem(int $projectId, ?int $estimateItemId): ?ScheduleTask
    {
        if (!$estimateItemId) {
            return null;
        }

        $tasks = ScheduleTask::query()
            ->where('estimate_item_id', $estimateItemId)
            ->whereHas('schedule', function ($query) use ($projectId): void {
                $query->where('project_id', $projectId);
            })
            ->orderByDesc('updated_at')
            ->get();

        return $tasks->count() === 1 ? $tasks->first() : null;
    }

    private function resolveTaskForEntry(ConstructionJournalEntry $entry, JournalWorkVolume $volume): ?ScheduleTask
    {
        if ($entry->scheduleTask) {
            return $entry->scheduleTask;
        }

        return $this->resolveTaskForEstimateItem($entry->journal->project_id, $volume->estimate_item_id);
    }

    private function buildPayloadFromJournalVolume(
        ConstructionJournalEntry $entry,
        JournalWorkVolume $volume,
        ?ScheduleTask $task,
    ): array {
        $contractLink = $volume->estimateItem?->contractLinks?->sortBy('id')->first()
            ?? $task?->estimateItem?->contractLinks?->sortBy('id')->first();

        $price = null;
        $totalAmount = null;

        if ($contractLink && (float) $contractLink->quantity > 0) {
            $price = round((float) $contractLink->amount / (float) $contractLink->quantity, 2);
            $totalAmount = round($price * (float) $volume->quantity, 2);
        }

        return [
            'organization_id' => $entry->journal->organization_id,
            'project_id' => $entry->journal->project_id,
            'schedule_task_id' => $task?->id,
            'estimate_item_id' => $volume->estimate_item_id ?? $task?->estimate_item_id,
            'journal_entry_id' => $entry->id,
            'work_origin_type' => CompletedWork::ORIGIN_JOURNAL,
            'planning_status' => $task ? CompletedWork::PLANNING_PLANNED : CompletedWork::PLANNING_REQUIRES_SCHEDULE,
            'contract_id' => $entry->journal->contract_id ?? $contractLink?->contract_id,
            'work_type_id' => $volume->work_type_id ?? $task?->work_type_id ?? $volume->estimateItem?->work_type_id,
            'user_id' => $entry->created_by_user_id,
            'contractor_id' => $contractLink?->contract?->contractor_id,
            'quantity' => (float) $volume->quantity,
            'completed_quantity' => (float) $volume->quantity,
            'price' => $price,
            'total_amount' => $totalAmount,
            'completion_date' => $entry->entry_date,
            'notes' => $volume->notes ?: $entry->work_description,
            'status' => $this->mapJournalStatusToCompletedWorkStatus($entry->status),
            'additional_info' => array_filter([
                'journal_entry_number' => $entry->entry_number,
                'journal_status' => $entry->status?->value,
                'weather_conditions' => $entry->weather_conditions,
            ], static fn ($value) => $value !== null && $value !== []),
        ];
    }

    private function mapJournalStatusToCompletedWorkStatus(JournalEntryStatusEnum|string|null $status): string
    {
        $resolvedStatus = $status instanceof JournalEntryStatusEnum ? $status : JournalEntryStatusEnum::from((string) $status);

        return match ($resolvedStatus) {
            JournalEntryStatusEnum::DRAFT => 'draft',
            JournalEntryStatusEnum::SUBMITTED => 'in_review',
            JournalEntryStatusEnum::APPROVED => 'confirmed',
            JournalEntryStatusEnum::REJECTED => 'rejected',
        };
    }

    private function syncTaskById(int $taskId): void
    {
        $task = ScheduleTask::query()->find($taskId);

        if ($task) {
            $this->scheduleTaskCompletedWorkService->syncCompletedQuantity($task);
        }
    }

    private function resolveTaskName(CompletedWork $completedWork): string
    {
        $baseName = $completedWork->workType?->name;

        if (!$baseName && is_string($completedWork->notes) && trim($completedWork->notes) !== '') {
            $baseName = trim(mb_substr($completedWork->notes, 0, 120));
        }

        return $baseName ?: 'Внеплановая работа';
    }
}
