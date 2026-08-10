<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\SheetAnalysis;

final readonly class PlanSheetAnalysis implements SheetAnalysisContract
{
    /** @param list<SheetAnalysisFact> $items */
    public function __construct(private array $items) {}

    public function role(): string
    {
        return 'plan';
    }

    public function facts(): array
    {
        return $this->items;
    }
}
