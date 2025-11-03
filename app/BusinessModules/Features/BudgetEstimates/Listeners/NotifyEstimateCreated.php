<?php

namespace App\BusinessModules\Features\BudgetEstimates\Listeners;

use App\BusinessModules\Features\BudgetEstimates\Events\EstimateCreated;
use Illuminate\Contracts\Queue\ShouldQueue;

class NotifyEstimateCreated implements ShouldQueue
{
    /**
     * Handle the event.
     */
    public function handle(EstimateCreated $event): void
    {
        $estimate = $event->estimate;

        try {
            // Отправить уведомление через модуль Notifications
            // NotificationService::send(...);
            
            \Log::info('estimate.created', [
                'estimate_id' => $estimate->id,
                'estimate_number' => $estimate->number,
                'organization_id' => $estimate->organization_id,
                'project_id' => $estimate->project_id,
            ]);
        } catch (\Exception $e) {
            \Log::error('estimate.notification_failed', [
                'estimate_id' => $estimate->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

