<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

final class NoopNormativeRolloutFaultInjector implements NormativeRolloutFaultInjector
{
    public function after(string $phase): void {}
}
