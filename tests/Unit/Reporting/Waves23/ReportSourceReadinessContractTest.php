<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Readiness\ReportCandidateReadinessGate;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceBackfillCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceReadiness;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSourceReadinessStatus;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReportSourceReadinessContractTest extends TestCase
{
    #[Test]
    public function complete_verified_projection_is_candidate_ready(): void
    {
        $readiness = new ReportSourceReadiness(
            status: ReportSourceReadinessStatus::READY,
            eligibleCount: 12,
            projectedCount: 12,
            gapCount: 0,
            unknownCount: 0,
            watermark: 'event:81',
            inputHash: str_repeat('a', 64),
            outputHash: str_repeat('b', 64),
            verifiedAt: new CarbonImmutable('2026-07-30T10:00:00+03:00'),
        );

        (new ReportCandidateReadinessGate)->assertReady(
            'accepted_production_progress',
            $readiness,
        );

        self::assertTrue($readiness->isReady());
    }

    #[Test]
    public function coverage_gap_is_rejected_even_when_status_claims_ready(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $readiness = new ReportSourceReadiness(
            status: ReportSourceReadinessStatus::READY,
            eligibleCount: 12,
            projectedCount: 11,
            gapCount: 1,
            unknownCount: 0,
            watermark: 'event:81',
            inputHash: str_repeat('a', 64),
            outputHash: str_repeat('b', 64),
            verifiedAt: new CarbonImmutable('2026-07-30T10:00:00+03:00'),
        );
    }

    #[Test]
    public function incomplete_projection_is_blocked_by_candidate_gate(): void
    {
        $readiness = new ReportSourceReadiness(
            status: ReportSourceReadinessStatus::PARTIAL,
            eligibleCount: 12,
            projectedCount: 11,
            gapCount: 1,
            unknownCount: 0,
            watermark: 'event:81',
            inputHash: str_repeat('a', 64),
            outputHash: str_repeat('b', 64),
            verifiedAt: null,
        );

        $this->expectException(ReportContractException::class);
        (new ReportCandidateReadinessGate)->assertReady(
            'accepted_production_progress',
            $readiness,
        );
    }

    #[Test]
    public function resume_cursor_is_bound_to_the_same_source_watermark(): void
    {
        $cursor = new ReportSourceBackfillCursor(
            lastSourceId: 500,
            sourceWatermark: 'sha256:'.str_repeat('c', 64),
        );

        self::assertSame(
            [
                'last_source_id' => 500,
                'source_watermark' => 'sha256:'.str_repeat('c', 64),
            ],
            $cursor->canonicalIdentity(),
        );
    }
}
