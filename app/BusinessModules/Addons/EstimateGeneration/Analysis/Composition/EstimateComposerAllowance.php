<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition;

interface EstimateComposerAllowance
{
    /** A legacy operation has no shared operation identifier and returns null. */
    public function partLimit(EstimateComposerInput $input): ?int;
}
