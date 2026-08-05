<?php

namespace App\BusinessModules\Core\Payments\Events;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use DateTimeInterface;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentDocumentPaid
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public PaymentDocument $document,
        public float|int|string $amount,
        public ?int $transactionId,
        public ?DateTimeInterface $recognizedAt,
        public ?int $organizationId,
        public ?int $projectId,
        public ?string $invoiceableType,
        public ?int $invoiceableId,
        public ?string $currency,
    ) {}
}
