<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

use InvalidArgumentException;

final readonly class BenchmarkCaseExecutionRequest
{
    public function __construct(
        public string $manifestReference,
        public string $caseId,
        public string $adapterId,
        public int $timeoutMs,
    ) {
        if (! preg_match('/^[a-z][a-z0-9._:-]{2,95}$/', $manifestReference)
            || ! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{2,95}$/', $caseId)
            || ! preg_match('/^[a-z][a-z0-9-]{2,63}$/', $adapterId)
            || $timeoutMs < 100 || $timeoutMs > 3_600_000) {
            throw new InvalidArgumentException('execution_request_invalid');
        }
    }
}
