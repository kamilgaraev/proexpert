<?php

declare(strict_types=1);

namespace App\Services\OneCExchange\DTOs;

final readonly class OneCExchangeDeliveryResult
{
    public function __construct(
        public bool $accepted,
        public string $status,
        public bool $retryable,
        public ?string $failureType,
        public ?string $safeErrorCode,
        public ?string $safeErrorMessage,
        public ?string $externalId,
        public ?int $transportStatus,
        public ?array $rawResponse = null,
    ) {
    }
}
