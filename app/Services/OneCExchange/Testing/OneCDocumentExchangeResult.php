<?php

declare(strict_types=1);

namespace App\Services\OneCExchange\Testing;

final readonly class OneCDocumentExchangeResult
{
    public function __construct(
        public bool $accepted,
        public string $syncStatus,
        public string $idempotencyKey,
        public ?string $externalId,
        public ?string $safeErrorCode,
        public ?string $safeErrorMessage,
        public bool $retryable,
        public ?array $rawResponse = null
    ) {
    }
}
