<?php

namespace App\BusinessModules\Features\SiteRequests\Events;

use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Событие смены статуса заявки
 */
class SiteRequestStatusChanged
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public readonly SiteRequest $siteRequest,
        public readonly string $oldStatus,
        public readonly string $newStatus,
        public readonly int $changedByUserId
    ) {}
}

