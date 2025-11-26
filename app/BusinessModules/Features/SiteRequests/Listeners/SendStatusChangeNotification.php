<?php

namespace App\BusinessModules\Features\SiteRequests\Listeners;

use App\BusinessModules\Features\SiteRequests\Events\SiteRequestStatusChanged;
use App\BusinessModules\Features\SiteRequests\Services\SiteRequestNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Слушатель для отправки уведомлений при смене статуса заявки
 */
class SendStatusChangeNotification implements ShouldQueue
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
    public function handle(SiteRequestStatusChanged $event): void
    {
        $this->notificationService->notifyOnStatusChange(
            $event->siteRequest,
            $event->oldStatus,
            $event->newStatus,
            $event->changedByUserId
        );
    }
}

