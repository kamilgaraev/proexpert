<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Quality;

use App\BusinessModules\Core\Reporting\Application\Quality\ReportQualityGateException;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityGateFailureCode;
use PHPUnit\Framework\TestCase;

final class ReportQualityGateExceptionTest extends TestCase
{
    public function test_exposes_the_closed_offline_failure_message_and_exit_code(): void
    {
        $exception = new ReportQualityGateException(ReportQualityGateFailureCode::MISSING);

        self::assertSame('quality-gate:missing', $exception->getMessage());
        self::assertSame(2, $exception->exitCode());
    }
}
