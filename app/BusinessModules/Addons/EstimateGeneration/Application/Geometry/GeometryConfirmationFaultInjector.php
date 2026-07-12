<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Geometry;

interface GeometryConfirmationFaultInjector
{
    public function afterLocksAcquired(): void;

    public function afterInvalidation(): void;
}
