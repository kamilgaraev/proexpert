<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

use DateTimeImmutable;

final class SystemNormativePinClock implements NormativePinClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now');
    }
}
