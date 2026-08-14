<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition;

interface EstimateComposerCorrectionModel
{
    /** @return array<string,mixed> */
    public function correct(EstimateComposerCorrectionInput $input, callable $onPhysicalAttemptReserved): array;
}
