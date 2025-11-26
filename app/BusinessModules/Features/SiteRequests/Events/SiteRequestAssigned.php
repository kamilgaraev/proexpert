<?php

namespace App\BusinessModules\Features\SiteRequests\Events;

use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Событие назначения исполнителя заявки
 */
class SiteRequestAssigned
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public readonly SiteRequest $siteRequest,
        public readonly int $assigneeId,
        public readonly int $assignedByUserId
    ) {}
}

