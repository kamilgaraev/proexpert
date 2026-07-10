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
        $transitionKey = isset($event->transitionKey)
            ? $event->transitionKey
            : hash('sha256', implode('|', [
                $event->siteRequest->id,
                $event->oldStatus,
                $event->newStatus,
                $event->changedByUserId,
                $event->siteRequest->created_at?->toISOString() ?? 'unknown',
            ]));

        $this->notificationService->notifyOnStatusChange(
            $event->siteRequest,
            $event->oldStatus,
            $event->newStatus,
            $event->changedByUserId,
            $transitionKey
        );
    }
}

