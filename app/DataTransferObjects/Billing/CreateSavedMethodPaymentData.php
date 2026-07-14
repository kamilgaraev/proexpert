<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Billing;

final readonly class CreateSavedMethodPaymentData
{
    public function __construct(
        public string $idempotenceKey,
        public int $amountMinor,
        public string $currency,
        public string $paymentMethodId,
        public string $description,
        public array $metadata,
        public ?string $customerEmail = null,
    ) {}
}
