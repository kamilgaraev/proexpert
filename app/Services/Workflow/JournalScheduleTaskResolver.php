<?php

declare(strict_types=1);

namespace App\Services\Workflow;

use App\Models\ConstructionJournalEntry;
use App\Models\JournalWorkVolume;
use App\Models\ScheduleTask;
use Illuminate\Support\Collection;

class JournalScheduleTaskResolver
{
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

        $tasks = ScheduleTask::query()
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
