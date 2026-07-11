<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

use InvalidArgumentException;

final readonly class BenchmarkRunOptions
{
    public function __construct(
        public string $pipelineVersion,
        public string $promptVersion,
        public int $caseTimeoutMs,
        public float $maxFailureRate,
        public string $failurePolicyVersion = 'strict-zero:v1',
        public bool $allowUnsupported = false,
    ) {
        foreach ([$pipelineVersion, $promptVersion] as $version) {
            if (! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{2,95}$/', $version)) {
                throw new InvalidArgumentException('benchmark_version_invalid');
            }
        }
        if ($caseTimeoutMs < 100 || $caseTimeoutMs > 3_600_000 || $maxFailureRate < 0 || $maxFailureRate > 1) {
            throw new InvalidArgumentException('benchmark_limits_invalid');
        }
        if (! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{2,95}$/', $failurePolicyVersion)) {
            throw new InvalidArgumentException('failure_policy_version_invalid');
        }
    }
}
