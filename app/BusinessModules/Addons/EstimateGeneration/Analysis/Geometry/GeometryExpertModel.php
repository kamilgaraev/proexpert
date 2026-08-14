<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Geometry;

interface GeometryExpertModel
{
    /** @return list<array<string,mixed>> */
    public function interpret(GeometryExpertInput $input, callable $onPhysicalAttemptReserved): array;
}
