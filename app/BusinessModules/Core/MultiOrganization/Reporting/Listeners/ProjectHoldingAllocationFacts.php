<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\Listeners;

use App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingPaymentTransactionEventVersion;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingPaymentEventFactProducer;
use App\BusinessModules\Core\Payments\Events\PaymentDocumentPaid;

final readonly class ProjectHoldingAllocationFacts
{
    public function __construct(
        private HoldingPaymentEventFactProducer $payments,
    ) {}

    public function handle(PaymentDocumentPaid $event): void
    {
        if ($event->transactionId === null || $event->transactionId < 1) {
            return;
        }

        $version = HoldingPaymentTransactionEventVersion::query()
            ->where('transaction_id', $event->transactionId)
            ->orderByDesc('occurred_at')
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->first();
        if ($version instanceof HoldingPaymentTransactionEventVersion) {
            $this->payments->project($version);
        }
    }
}
