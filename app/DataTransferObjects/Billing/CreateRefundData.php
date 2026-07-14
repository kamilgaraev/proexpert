<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Billing;

final readonly class CreateRefundData
{
    public function __construct(
        public string $idempotenceKey,
        public string $paymentId,
        public int $amountMinor,
        public string $currency,
        public string $description,
        public array $metadata,
    ) {}
}
