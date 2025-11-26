<?php

namespace App\BusinessModules\Features\SiteRequests\Listeners;

use App\BusinessModules\Features\SiteRequests\Events\SiteRequestUpdated;
use App\BusinessModules\Features\SiteRequests\Services\SiteRequestCalendarService;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Слушатель для обновления события в календаре при изменении заявки
 */
class UpdateCalendarEventOnSiteRequest implements ShouldQueue
{
    /**
     * Create the event listener.
     */
    public function __construct(
        private readonly SiteRequestCalendarService $calendarService
    ) {}

    /**
     * Handle the event.
     */
    public function handle(SiteRequestUpdated $event): void
    {
        // Обновляем событие в календаре только если изменились связанные с календарем поля
        $relevantFields = [
            'title',
            'required_date',
            'work_start_date',
            'work_end_date',
            'rental_start_date',
            'rental_end_date',
            'delivery_time_from',
            'delivery_time_to',
            'priority',
        ];

        $hasRelevantChanges = !empty(array_intersect_key($event->oldValues, array_flip($relevantFields)));

        if ($hasRelevantChanges) {
            $this->calendarService->updateCalendarEvent($event->siteRequest);
        }
    }
}

