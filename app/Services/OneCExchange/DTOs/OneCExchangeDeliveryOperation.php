<?php

declare(strict_types=1);

namespace App\Services\OneCExchange\DTOs;

use DateTimeImmutable;

final readonly class OneCExchangeDeliveryOperation
{
    public function __construct(
        public int $id,
        public int $organizationId,
        public string $operationKey,
        public string $correlationId,
        public string $direction,
        public string $scope,
        public ?string $entityType,
        public ?string $entityId,
        public ?string $idempotencyKey,
        public string $status,
        public int $retryCount,
        public int $maxAttempts,
        public ?string $failureType,
        public ?string $accountingStatus,
        public ?array $safePayloadPreview,
        public ?array $summary,
        public ?DateTimeImmutable $nextRetryAt,
    ) {
    }

    public function sourceIsActual(): bool
    {
        return (bool) ($this->summary['source_is_actual'] ?? true);
    }
}
