<?php

namespace App\BusinessModules\Features\BudgetEstimates\Listeners;

use App\BusinessModules\Features\BudgetEstimates\Events\JournalEntryApproved;
use App\BusinessModules\Features\BudgetEstimates\Services\Integration\JournalScheduleIntegrationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class UpdateScheduleProgressFromJournal implements ShouldQueue
{
    public function __construct(
        protected JournalScheduleIntegrationService $scheduleIntegrationService
    ) {}

    /**
     * Handle the event.
     */
    public function handle(JournalEntryApproved $event): void
    {
        $entry = $event->entry;

        if (!$entry->schedule_task_id) {
            return;
        }

        try {
            $task = $this->scheduleIntegrationService->updateTaskProgressFromEntry($entry);

            if ($task) {
                Log::info('construction_journal.schedule_progress_updated', [
                    'entry_id' => $entry->id,
                    'task_id' => $task->id,
                    'progress' => $task->progress_percent,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('construction_journal.schedule_progress_update_failed', [
                'entry_id' => $entry->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

