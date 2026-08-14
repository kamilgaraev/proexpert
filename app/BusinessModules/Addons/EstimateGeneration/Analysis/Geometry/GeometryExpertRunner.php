<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Geometry;

interface GeometryExpertRunner
{
    public function run(GeometryExpertInput $input): GeometryExpertResult;
}
