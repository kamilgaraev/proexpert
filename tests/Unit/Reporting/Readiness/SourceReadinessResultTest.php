<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Readiness;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\Support\Reporting\SourceReadinessResult;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class SourceReadinessResultTest extends TestCase
{
    public function test_empty_source_is_ready(): void
    {
        $result = SourceReadinessResult::empty(new DateTimeImmutable('2026-08-09T00:00:00Z'));

        $result->assertReady();

        self::assertSame(0, $result->eligibleCount);
        self::assertSame(0, $result->projectedCount);
        self::assertSame(0, $result->gapCount);
    }

    public function test_incomplete_source_uses_typed_retryable_error(): void
    {
        $result = new SourceReadinessResult(
            1,
            0,
            1,
            0,
            0,
            0,
            new DateTimeImmutable('2026-08-09T00:00:00Z'),
        );

        try {
            $result->assertReady();
            self::fail('Incomplete source must be rejected.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE, $exception->errorCode);
        }
    }
}
