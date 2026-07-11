<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

interface FailureWorkflowHandler
{
    public function handle(FailureData $failure, ?int $expectedStateVersion = null): void;
}
