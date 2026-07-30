<?php

declare(strict_types=1);

namespace Tests\Support\Reporting;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionTelemetry;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportExecutionRuntimeConfiguration;

final class ReportRuntimeFixture
{
    public static function configuration(): ReportExecutionRuntimeConfiguration
    {
        return new ReportExecutionRuntimeConfiguration(
            100,
            60,
            12,
            100,
            300,
            12,
            960,
            100,
        );
    }

    public static function telemetry(): ReportExecutionTelemetry
    {
        return new class implements ReportExecutionTelemetry
        {
            public function runTransition(string $reportCode, string $status): void {}

            public function runDuration(string $reportCode, string $status, float $seconds): void {}

            public function exportTransition(string $reportCode, string $format, string $status): void {}

            public function exportDuration(string $reportCode, string $format, string $status, float $seconds): void {}

            public function exportArtifact(string $reportCode, string $format, int $rows, int $bytes): void {}

            public function multipartAbort(string $reportCode, string $format): void {}

            public function dispatchIntent(string $intentType, string $topic, string $outcome, float $ageSeconds): void {}

            public function executionAttempt(string $intentType, string $errorCode): void {}

            public function executionLeaseReclaimed(string $intentType): void {}

            public function auditDeliveryFailure(string $errorCode, string $outcome): void {}
        };
    }
}
