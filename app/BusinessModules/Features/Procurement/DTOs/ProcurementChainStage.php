<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\DTOs;

final readonly class ProcurementChainStage
{
    public function __construct(
        public string $key,
        public string $label,
        public string $status,
        public ?string $description = null,
        public ?ProcurementChainDocumentLink $document = null,
        public ?ProcurementChainBlocker $blocker = null,
        public string $severity = 'neutral',
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'status' => $this->status,
            'description' => $this->description,
            'document' => $this->document?->toArray(),
            'blocker' => $this->blocker?->toArray(),
            'severity' => $this->severity,
        ];
    }
}
