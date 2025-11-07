<?php

namespace App\BusinessModules\Features\ScheduleManagement\Listeners;

use App\BusinessModules\Features\ScheduleManagement\Services\EstimateSyncService;
use App\Models\ProjectSchedule;
use Illuminate\Support\Facades\Log;

class SyncScheduleOnEstimateUpdate
{
    public function __construct(
        private readonly EstimateSyncService $syncService
    ) {}

    /**
     * Handle the event.
     * 
     * @param object $event Событие обновления сметы (EstimateUpdated)
     * @return void
     */
    public function handle(object $event): void
    {
        // Проверяем, что событие содержит смету
        if (!isset($event->estimate)) {
            return;
        }

        $estimate = $event->estimate;

        // Находим все графики, связанные с этой сметой
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

        // Помечаем все связанные графики как рассинхронизированные
        foreach ($schedules as $schedule) {
            $this->syncService->markScheduleAsOutOfSync($schedule);

            Log::info('schedule.marked_out_of_sync', [
                'schedule_id' => $schedule->id,
                'estimate_id' => $estimate->id,
            ]);
        }

        // TODO: Опционально можно добавить автоматическую синхронизацию
        // if (настройка авто-синхронизации включена) {
        //     foreach ($schedules as $schedule) {
        //         try {
        //             $this->syncService->syncScheduleWithEstimate($schedule, false);
        //         } catch (\Exception $e) {
        //             Log::error('schedule.auto_sync_failed', [
        //                 'schedule_id' => $schedule->id,
        //                 'error' => $e->getMessage(),
        //             ]);
        //         }
        //     }
        // }
    }
}

