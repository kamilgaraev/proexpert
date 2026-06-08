<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\DTOs;

final readonly class PlanFactDrillDownResult
{
    /**
     * @param list<PlanFactDrillDownItem> $items
     * @param list<string> $warnings
     */
    public function __construct(
        public array $filters,
        public array $period,
        public array $group,
        public array $summary,
        public array $items,
        public array $warnings,
        public array $meta,
    ) {
    }

    public function toArray(): array
    {
        return [
            'filters' => $this->filters,
            'period' => $this->period,
            'group' => $this->group,
            'summary' => $this->summary,
            'items' => array_map(
                static fn (PlanFactDrillDownItem $item): array => $item->toArray(),
                $this->items,
            ),
            'warnings' => $this->warnings,
            'meta' => $this->meta,
        ];
    }
}
