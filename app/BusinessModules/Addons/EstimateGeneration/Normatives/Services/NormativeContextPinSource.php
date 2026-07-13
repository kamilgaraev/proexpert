<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

interface NormativeContextPinSource
{
    public function resolve(NormativeContextPinData $requested): ?NormativeContextPinData;
}
