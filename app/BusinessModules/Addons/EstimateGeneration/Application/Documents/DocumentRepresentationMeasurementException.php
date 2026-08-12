<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use RuntimeException;
use Throwable;

final class DocumentRepresentationMeasurementException extends RuntimeException
{
    public function __construct(
        public readonly DocumentRepresentationMeasurement $measurement,
        Throwable $previous,
    ) {
        parent::__construct($previous->getMessage(), previous: $previous);
    }
}
