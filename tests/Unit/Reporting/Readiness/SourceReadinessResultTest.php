<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Readiness;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceReadinessIssues;
use App\Support\Reporting\SourceReadinessResult;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_source_issues_survive_the_readiness_contract_error(): void
    {
        $issues = new ReportSourceReadinessIssues(7, 0, 0, 0, 0);
        $result = new SourceReadinessResult(7, 7, 7, 0, 0, 0, new DateTimeImmutable, $issues);

        try {
            $result->assertReady();
            self::fail('Missing opening evidence must prevent a run.');
        } catch (ReportContractException $exception) {
            self::assertSame($issues, $exception->sourceIssues);
        }
    }

    #[DataProvider('negativeIssues')]
    public function test_source_issue_counts_cannot_be_negative(array $counts): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ReportSourceReadinessIssues(...$counts);
    }

    public static function negativeIssues(): array
    {
        return [[[-1, 0, 0, 0, 0]], [[0, -1, 0, 0, 0]], [[0, 0, -1, 0, 0]], [[0, 0, 0, -1, 0]], [[0, 0, 0, 0, -1]]];
    }

    public function test_source_issues_cannot_be_attached_to_other_errors(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR, sourceIssues: new ReportSourceReadinessIssues(1, 0, 0, 0, 0));
    }
}
