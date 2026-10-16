<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

use InvalidArgumentException;
use Throwable;

final class BenchmarkContractException extends InvalidArgumentException
{
    public function __construct(public readonly string $reason, ?Throwable $previous = null)
    {
        parent::__construct($reason, 0, $previous);
    }
}
