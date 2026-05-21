<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Listeners;

use App\BusinessModules\Features\ScheduleManagement\Services\EstimateSyncService;
use App\Models\ProjectSchedule;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncScheduleOnEstimateUpdate
{
    public function __construct(
        private readonly EstimateSyncService $syncService
    ) {}

    public function handle(object $event): void
    {
        if (!isset($event->estimate)) {
            return;
        }

        $estimate = $event->estimate;

        $schedules = ProjectSchedule::where('estimate_id', $estimate->id)
            ->where('sync_with_estimate', true)
            ->get();

        if ($schedules->isEmpty()) {
            return;
        }

        Log::info('schedule.estimate_updated', [
            'estimate_id' => $estimate->id,
            'linked_schedules' => $schedules->count(),
        ]);

        foreach ($schedules as $schedule) {
            try {
                $results = $this->syncService->syncScheduleWithEstimate($schedule);

                Log::info('schedule.auto_synced_with_estimate', [
                    'schedule_id' => $schedule->id,
                    'estimate_id' => $estimate->id,
                    'results' => $results,
                ]);
            } catch (Throwable $e) {
                $this->syncService->markScheduleAsConflict($schedule);

                Log::error('schedule.auto_sync_failed', [
                    'schedule_id' => $schedule->id,
                    'estimate_id' => $estimate->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
