<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SiteRequests\Listeners;

use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestStatusEnum;
use App\BusinessModules\Features\SiteRequests\Events\SiteRequestStatusChanged;
use App\BusinessModules\Features\SiteRequests\Services\SiteRequestCalendarService;
use App\BusinessModules\Features\SiteRequests\Services\SiteRequestNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;

final class PublishSiteRequestOnSubmit implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(
        private readonly SiteRequestCalendarService $calendarService,
        private readonly SiteRequestNotificationService $notificationService
    ) {}

    public function handle(SiteRequestStatusChanged $event): void
    {
        if (
            $event->oldStatus !== SiteRequestStatusEnum::DRAFT->value
            || $event->newStatus !== SiteRequestStatusEnum::PENDING->value
        ) {
            return;
        }

        $this->calendarService->updateCalendarEvent($event->siteRequest);
        $this->notificationService->notifyOnCreated($event->siteRequest);
    }
}
