<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

final class NullFailureRecorderObserver implements FailureRecorderObserver
{
    public function recordingFailed(string $failureCode, string $fingerprint): void {}
}
