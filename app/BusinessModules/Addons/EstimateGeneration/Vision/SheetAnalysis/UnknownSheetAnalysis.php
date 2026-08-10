<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\SheetAnalysis;

final readonly class UnknownSheetAnalysis implements SheetAnalysisContract
{
    public function role(): string
    {
        return 'unknown';
    }

    public function facts(): array
    {
        return [];
    }
}
