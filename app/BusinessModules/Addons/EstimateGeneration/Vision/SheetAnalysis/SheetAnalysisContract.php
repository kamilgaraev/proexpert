<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\SheetAnalysis;

interface SheetAnalysisContract
{
    public function role(): string;

    /** @return list<SheetAnalysisFact> */
    public function facts(): array;
}
