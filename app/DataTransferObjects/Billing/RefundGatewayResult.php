<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Billing;

final readonly class RefundGatewayResult
{
    public function __construct(
        public string $id,
        public string $paymentId,
        public string $status,
        public int $amountMinor,
        public string $currency,
        public array $safeResponse,
        public array $metadata = [],
    ) {}
}
