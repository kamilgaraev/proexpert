<?php

namespace App\BusinessModules\Features\SiteRequests\Listeners;

use App\BusinessModules\Features\SiteRequests\Events\SiteRequestCreated;
use App\BusinessModules\Features\SiteRequests\Services\SiteRequestCalendarService;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Слушатель для создания события в календаре при создании заявки
 */
class CreateCalendarEventOnSiteRequest implements ShouldQueue
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
    public function handle(SiteRequestCreated $event): void
    {
        $this->calendarService->createCalendarEvent($event->siteRequest);
    }
}

