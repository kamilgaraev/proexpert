<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

interface DocumentRepresentationResourceMeter
{
    /** @param callable(): mixed $operation */
    public function measure(callable $operation): DocumentRepresentationMeasurement;
}
