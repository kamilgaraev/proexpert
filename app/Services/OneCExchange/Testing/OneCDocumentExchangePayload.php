<?php

declare(strict_types=1);

namespace App\Services\OneCExchange\Testing;

final readonly class OneCDocumentExchangePayload
{
    public function __construct(
        public string $scope,
        public string $entityType,
        public int $entityId,
        public int $version,
        public string $idempotencyKey,
        public array $safePayloadPreview
    ) {
    }
}
