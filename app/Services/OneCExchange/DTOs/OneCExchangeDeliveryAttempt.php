<?php

declare(strict_types=1);

namespace App\Services\OneCExchange\DTOs;

use DateTimeImmutable;

final readonly class OneCExchangeDeliveryAttempt
{
    public function __construct(
        public string $status,
        public ?string $failureType,
        public bool $retryable,
        public ?DateTimeImmutable $nextRetryAt,
        public ?string $safeErrorCode,
        public ?string $safeErrorMessage,
        public ?int $transportStatus,
        public array $safeRequestPreview,
        public array $safeResponsePreview,
        public int $durationMs,
        public ?string $externalId,
        public ?string $accountingStatus,
    ) {
    }
}
