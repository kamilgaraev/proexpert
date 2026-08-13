<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

interface FailureRecorderObserver
{
    public function recordingFailed(FailureData $failure): void;
}
