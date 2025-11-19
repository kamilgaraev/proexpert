<?php

namespace App\BusinessModules\Core\Payments\Events;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentDocumentOverdue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public PaymentDocument $document,
        public int $overdueDays
    ) {}
}

