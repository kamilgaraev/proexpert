<?php

namespace App\BusinessModules\Features\SiteRequests\Listeners;

use App\BusinessModules\Features\SiteRequests\Events\SiteRequestCreated;
use App\BusinessModules\Features\SiteRequests\Services\SiteRequestNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Слушатель для отправки уведомлений при создании заявки
 */
class SendSiteRequestNotification implements ShouldQueue
{
    /**
     * Create the event listener.
     */
    public function __construct(
        private readonly SiteRequestNotificationService $notificationService
    ) {}

    /**
     * Handle the event.
     */
    public function handle(SiteRequestCreated $event): void
    {
        $this->notificationService->notifyOnCreated($event->siteRequest);
    }
}

