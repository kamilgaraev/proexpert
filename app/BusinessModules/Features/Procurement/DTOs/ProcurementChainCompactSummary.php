<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\DTOs;

final readonly class ProcurementChainCompactSummary
{
    public function __construct(
        public string $stage,
        public string $stageLabel,
        public ?string $nextActionLabel,
        public bool $isBlocked,
        public int $blockersCount,
        public ?string $primaryDocumentHref,
        public string $mapHref,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'stage' => $this->stage,
            'stage_label' => $this->stageLabel,
            'next_action_label' => $this->nextActionLabel,
            'is_blocked' => $this->isBlocked,
            'blockers_count' => $this->blockersCount,
            'primary_document_href' => $this->primaryDocumentHref,
            'map_href' => $this->mapHref,
        ];
    }
}
