<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\DTOs;

final readonly class ProcurementChainBlocker
{
    public function __construct(
        public string $key,
        public string $message,
        public string $severity = 'warning',
        public ?string $entityType = null,
        public ?int $entityId = null,
        public ?ProcurementChainAction $action = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'message' => $this->message,
            'severity' => $this->severity,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'action' => $this->action?->toArray(),
        ];
    }
}
