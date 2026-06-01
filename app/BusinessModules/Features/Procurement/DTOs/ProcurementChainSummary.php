<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\DTOs;

use Illuminate\Support\Collection;

final readonly class ProcurementChainSummary
{
    /**
     * @param array{type: string, id: int, label: string, href: string} $root
     * @param Collection<int, ProcurementChainBlocker> $blockers
     * @param Collection<int, ProcurementChainBlocker> $warnings
     * @param Collection<int, ProcurementChainDocumentLink> $linkedDocuments
     * @param Collection<int, ProcurementChainStage> $stages
     * @param array<string, bool> $permissions
     */
    public function __construct(
        public array $root,
        public ProcurementChainStage $currentStage,
        public ?ProcurementChainAction $nextAction,
        public Collection $blockers,
        public Collection $warnings,
        public Collection $linkedDocuments,
        public Collection $stages,
        public array $permissions = [],
    ) {
    }

    public function compact(): ProcurementChainCompactSummary
    {
        return new ProcurementChainCompactSummary(
            stage: $this->currentStage->key,
            stageLabel: $this->currentStage->label,
            nextActionLabel: $this->nextAction?->label,
            isBlocked: $this->blockers->isNotEmpty(),
            blockersCount: $this->blockers->count(),
            primaryDocumentHref: $this->currentStage->document?->href ?? $this->root['href'],
            mapHref: $this->root['href'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'root' => $this->root,
            'current_stage' => $this->currentStage->toArray(),
            'next_action' => $this->nextAction?->toArray(),
            'blockers' => $this->blockers->map->toArray()->values()->all(),
            'warnings' => $this->warnings->map->toArray()->values()->all(),
            'linked_documents' => $this->linkedDocuments->map->toArray()->values()->all(),
            'stages' => $this->stages->map->toArray()->values()->all(),
            'permissions' => $this->permissions,
            'compact' => $this->compact()->toArray(),
        ];
    }
}
