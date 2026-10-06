<?php

declare(strict_types=1);

namespace App\Services\Workflow;

use App\Models\ConstructionJournalEntry;
use App\Models\JournalWorkVolume;
use App\Models\ScheduleTask;
use Illuminate\Support\Collection;

class JournalScheduleTaskResolver
{
    public function loadForEntryPage(Collection $entries): void
    {
        $groups = [];
        foreach ($entries as $entry) {
            if ($entry->schedule_task_id) {
                continue;
            }

            foreach ($entry->workVolumes as $volume) {
                if ($volume->estimate_item_id) {
                    $groups[$entry->journal->project_id][$volume->estimate_item_id] = true;
                }
            }
        }

        if ($groups === []) {
            return;
        }

        $tasks = ScheduleTask::query()
            ->with('schedule')
            ->where(function ($query) use ($groups): void {
                foreach ($groups as $projectId => $itemIds) {
                    $query->orWhere(function ($query) use ($projectId, $itemIds): void {
                        $query->whereIn('estimate_item_id', array_keys($itemIds))
                            ->whereHas('schedule', fn ($query) => $query->where('project_id', $projectId));
                    });
                }
            })
            ->orderByDesc('updated_at')
            ->get()
            ->filter(fn (ScheduleTask $task): bool => $task->schedule !== null)
            ->groupBy(fn (ScheduleTask $task): string => $task->schedule->project_id.':'.$task->estimate_item_id);

        foreach ($entries as $entry) {
            if ($entry->schedule_task_id) {
                continue;
            }

            foreach ($entry->workVolumes as $volume) {
                $key = $entry->journal->project_id.':'.$volume->estimate_item_id;
                $volume->setRelation('journalScheduleTasks', $tasks->get($key, collect()));
            }
        }
    }

    public function resolveForVolume(ConstructionJournalEntry $entry, JournalWorkVolume $volume): ?ScheduleTask
    {
        if ($entry->schedule_task_id) {
            return $entry->scheduleTask instanceof ScheduleTask
                ? $entry->scheduleTask
                : ScheduleTask::query()->find($entry->schedule_task_id);
        }

        if (! $volume->estimate_item_id) {
            return null;
        }

        $tasks = $volume->relationLoaded('journalScheduleTasks')
            ? $volume->getRelation('journalScheduleTasks')
            : ScheduleTask::query()
            ->where('estimate_item_id', $volume->estimate_item_id)
            ->whereHas('schedule', function ($query) use ($entry): void {
                $query->where('project_id', $entry->journal->project_id);
            })
            ->orderByDesc('updated_at')
            ->get();

        return $tasks->count() === 1 ? $tasks->first() : null;
    }

    public function allVolumesHaveResolvableTask(ConstructionJournalEntry $entry): bool
    {
        $entry->loadMissing(['journal', 'scheduleTask', 'workVolumes']);

        if ($entry->schedule_task_id) {
            return true;
        }

        if ($entry->workVolumes->isEmpty()) {
            return false;
        }

        return $entry->workVolumes
            ->every(fn (JournalWorkVolume $volume): bool => $this->resolveForVolume($entry, $volume) instanceof ScheduleTask);
    }

    public function resolveUniqueTaskForEntry(ConstructionJournalEntry $entry): ?ScheduleTask
    {
        $entry->loadMissing(['journal', 'scheduleTask', 'workVolumes']);

        if ($entry->schedule_task_id) {
            return $entry->scheduleTask instanceof ScheduleTask
                ? $entry->scheduleTask
                : ScheduleTask::query()->find($entry->schedule_task_id);
        }

        if (! $this->allVolumesHaveResolvableTask($entry)) {
            return null;
        }

        $tasks = $this->resolvedTasksForEntry($entry);

        return $tasks->count() === 1 ? $tasks->first() : null;
    }

    private function resolvedTasksForEntry(ConstructionJournalEntry $entry): Collection
    {
        return $entry->workVolumes
            ->map(fn (JournalWorkVolume $volume): ?ScheduleTask => $this->resolveForVolume($entry, $volume))
            ->filter()
            ->unique('id')
            ->values();
    }
}
