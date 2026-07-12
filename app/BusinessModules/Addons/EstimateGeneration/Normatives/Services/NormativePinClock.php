<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

use DateTimeImmutable;

interface NormativePinClock
{
    public function now(): DateTimeImmutable;
}
