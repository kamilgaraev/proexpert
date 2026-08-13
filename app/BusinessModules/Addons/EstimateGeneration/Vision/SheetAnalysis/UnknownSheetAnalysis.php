<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\SheetAnalysis;

final readonly class UnknownSheetAnalysis implements SheetAnalysisContract
{
    /** @param list<SheetAnalysisFact> $items */
    public function __construct(private array $items = []) {}

    public function role(): string
    {
        return 'unknown';
    }

    public function facts(): array
    {
        return $this->items;
    }
}
