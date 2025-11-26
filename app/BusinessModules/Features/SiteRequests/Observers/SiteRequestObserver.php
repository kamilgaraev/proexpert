<?php

namespace App\BusinessModules\Features\SiteRequests\Observers;

use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\BusinessModules\Features\SiteRequests\Services\SiteRequestCalendarService;

/**
 * Observer для модели SiteRequest
 */
class SiteRequestObserver
{
    /**
     * Create the observer.
     */
    public function __construct(
        private readonly SiteRequestCalendarService $calendarService
    ) {}

    /**
     * Handle the SiteRequest "deleted" event.
     */
    public function deleted(SiteRequest $siteRequest): void
    {
        // Удаляем событие календаря при удалении заявки
        $this->calendarService->deleteCalendarEvent($siteRequest);
    }

    /**
     * Handle the SiteRequest "restored" event.
     */
    public function restored(SiteRequest $siteRequest): void
    {
        // Восстанавливаем событие календаря при восстановлении заявки
        if ($siteRequest->hasCalendarEvent()) {
            $this->calendarService->createCalendarEvent($siteRequest);
        }
    }

    /**
     * Handle the SiteRequest "force deleted" event.
     */
    public function forceDeleted(SiteRequest $siteRequest): void
    {
        // Удаляем событие календаря при полном удалении заявки
        $this->calendarService->deleteCalendarEvent($siteRequest);
    }
}

