<?php

namespace App\BusinessModules\Features\SiteRequests\Events;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * Событие создания платежа из заявок
 */
class SiteRequestPaymentCreated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public readonly PaymentDocument $paymentDocument,
        public readonly Collection $siteRequests,
        public readonly int $createdByUserId
    ) {}
}

