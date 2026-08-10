<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\SheetAnalysis;

use InvalidArgumentException;

final readonly class SpecificationSheetAnalysis implements SheetAnalysisContract
{
    /** @param list<SheetAnalysisFact> $items */
    public function __construct(private string $sheetRole, private array $items)
    {
        if (! in_array($sheetRole, ['explication', 'specification'], true)) {
            throw new InvalidArgumentException('Invalid specification sheet role.');
        }
    }

    public function role(): string
    {
        return $this->sheetRole;
    }

    public function facts(): array
    {
        return $this->items;
    }
}
