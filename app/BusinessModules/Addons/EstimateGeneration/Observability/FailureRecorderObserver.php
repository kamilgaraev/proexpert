<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

interface FailureRecorderObserver
{
    public function recordingFailed(string $failureCode, string $fingerprint): void;
}
