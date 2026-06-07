<?php

declare(strict_types=1);

namespace App\Services\OneCExchange\DTOs;

final readonly class OneCExchangeDeliveryPayload
{
    public function __construct(
        public int $operationId,
        public int $organizationId,
        public string $operationKey,
        public string $correlationId,
        public string $direction,
        public string $scope,
        public ?string $entityType,
        public ?string $entityId,
        public ?string $idempotencyKey,
        public array $safePayloadPreview,
    ) {
    }

    public function toRequestArray(): array
    {
        return [
            'operation_id' => $this->operationId,
            'organization_id' => $this->organizationId,
            'operation_key' => $this->operationKey,
            'correlation_id' => $this->correlationId,
            'direction' => $this->direction,
            'scope' => $this->scope,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'idempotency_key' => $this->idempotencyKey,
            'safe_payload_preview' => $this->safePayloadPreview,
        ];
    }
}
