<?php

namespace App\BusinessModules\Features\SiteRequests\Events;

use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Событие обновления заявки
 */
class SiteRequestUpdated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public readonly SiteRequest $siteRequest,
        public readonly array $oldValues = []
    ) {}
}

