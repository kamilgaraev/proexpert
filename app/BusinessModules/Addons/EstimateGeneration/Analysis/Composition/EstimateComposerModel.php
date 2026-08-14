<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition;

interface EstimateComposerModel
{
    /** @param callable(string):void $onPhysicalAttemptReserved @return array<string, mixed> */
    public function compose(EstimateComposerInput $input, callable $onPhysicalAttemptReserved): array;
}
