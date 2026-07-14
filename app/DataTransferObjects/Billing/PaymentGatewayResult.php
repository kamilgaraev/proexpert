<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Billing;

final readonly class PaymentGatewayResult
{
    public function __construct(
        public string $id,
        public string $status,
        public ?string $confirmationUrl,
        public ?string $paymentMethodId,
        public bool $paymentMethodSaved,
        public array $safeResponse,
        public bool $paid = false,
        public bool $test = false,
        public int $amountMinor = 0,
        public string $currency = '',
        public array $metadata = [],
        public int $refundedAmountMinor = 0,
    ) {}
}
