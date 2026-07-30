<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Execution;

use InvalidArgumentException;

final readonly class ReportExecutionRuntimeConfiguration
{
    public static function canonical(): self
    {
        return new self(100, 60, 12, 100, 300, 12, 960, 100);
    }

    public function __construct(
        public int $dispatchBatchSize,
        public int $dispatchLeaseSeconds,
        public int $dispatchMaxAttempts,
        public int $auditBatchSize,
        public int $auditLeaseSeconds,
        public int $auditMaxAttempts,
        public int $executionLeaseSeconds,
        public int $watchdogBatchSize,
    ) {
        if (
            $dispatchBatchSize !== 100
            || $dispatchLeaseSeconds !== 60
            || $dispatchMaxAttempts !== 12
            || $auditBatchSize !== 100
            || $auditLeaseSeconds !== 300
            || $auditMaxAttempts !== 12
            || $executionLeaseSeconds !== 960
            || $watchdogBatchSize !== 100
            || $auditLeaseSeconds <= 120
        ) {
            throw new InvalidArgumentException(
                'reporting_execution_runtime_configuration_invalid',
            );
        }
    }
}
