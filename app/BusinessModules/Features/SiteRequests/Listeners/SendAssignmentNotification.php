<?php

namespace App\BusinessModules\Features\SiteRequests\Listeners;

use App\BusinessModules\Features\SiteRequests\Events\SiteRequestAssigned;
use App\BusinessModules\Features\SiteRequests\Services\SiteRequestNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Слушатель для отправки уведомлений при назначении исполнителя
 */
class SendAssignmentNotification implements ShouldQueue
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
    public function handle(SiteRequestAssigned $event): void
    {
        $this->notificationService->notifyOnAssigned(
            $event->siteRequest,
            $event->assigneeId,
            $event->assignedByUserId
        );
    }
}

