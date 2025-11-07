<?php

namespace App\BusinessModules\Features\ScheduleManagement\Listeners;

use App\BusinessModules\Features\ScheduleManagement\Services\EstimateSyncService;
use App\BusinessModules\Features\ScheduleManagement\Events\ScheduleProgressUpdated;
use Illuminate\Support\Facades\Log;

class UpdateEstimateProgressOnScheduleChange
{
    public function __construct(
        private readonly EstimateSyncService $syncService
    ) {}

    /**
     * Handle the event.
     * 
     * @param ScheduleProgressUpdated $event
     * @return void
     */
    public function handle(ScheduleProgressUpdated $event): void
    {
        $schedule = $event->schedule;

        // Проверяем, связан ли график со сметой
        if (!$schedule->estimate_id || !$schedule->sync_with_estimate) {
            return;
        }

        // Синхронизируем прогресс в смету
        try {
            $results = $this->syncService->syncEstimateProgress($schedule);

            Log::info('estimate.progress_synced', [
                'schedule_id' => $schedule->id,
                'estimate_id' => $schedule->estimate_id,
                'updated_items' => $results['updated'],
            ]);
        } catch (\Exception $e) {
            Log::error('estimate.progress_sync_failed', [
                'schedule_id' => $schedule->id,
                'estimate_id' => $schedule->estimate_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

