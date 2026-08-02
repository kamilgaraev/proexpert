<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Core\Reporting\Application\Rows\StableDrillDownPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceBackfillCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceBackfillResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceReadiness;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSourceReadinessStatus;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReportSourceControlContractsTest extends TestCase
{
    #[Test]
    public function empty_backfill_cursor_is_a_valid_resume_origin(): void
    {
        self::assertSame([], ReportSourceBackfillCursor::start()->position);
    }

    #[Test]
    public function backfill_result_requires_closed_coverage_equation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_source_backfill_result_invalid');

        new ReportSourceBackfillResult(
            new ReportSourceBackfillCursor(['id' => 10]),
            eligibleCount: 10,
            projectedCount: 8,
            gapCount: 0,
            unknownCount: 1,
            outputHash: str_repeat('a', 64),
            complete: false,
        );
    }

    #[Test]
    public function ready_source_requires_zero_gap_and_a_verification_time(): void
    {
        $readiness = new ReportSourceReadiness(
            ReportSourceReadinessStatus::READY,
            eligibleCount: 2,
            projectedCount: 2,
            gapCount: 0,
            unknownCount: 0,
            watermark: 'owner_10',
            inputHash: str_repeat('a', 64),
            outputHash: str_repeat('b', 64),
            verifiedAt: CarbonImmutable::parse('2026-07-30T10:00:00Z'),
        );

        self::assertSame($readiness->eligibleCount, $readiness->projectedCount);
    }

    #[Test]
    public function drill_down_pages_resume_after_the_last_stable_row_without_duplicates(): void
    {
        $rows = [
            ['row_key' => 'row-03', 'value' => 3],
            ['row_key' => 'row-01', 'value' => 1],
            ['row_key' => 'row-02', 'value' => 2],
        ];

        $first = StableDrillDownPage::fromRows($rows, null, 2);
        $second = StableDrillDownPage::fromRows($rows, $first->nextCursor, 2);

        self::assertSame(['row-01', 'row-02'], array_column($first->rows, 'row_key'));
        self::assertSame('row-02', $first->nextCursor);
        self::assertSame(['row-03'], array_column($second->rows, 'row_key'));
        self::assertNull($second->nextCursor);
    }
}
