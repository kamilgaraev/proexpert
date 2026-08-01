<?php

declare(strict_types=1);

namespace Tests\Unit\QualityControl\Reporting\DefectFlow;

use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Services\QualityDefectFlowIdempotencyGuard;
use LogicException;
use PHPUnit\Framework\TestCase;

final class QualityDefectFlowIdempotencyGuardTest extends TestCase
{
    public function test_accepts_exact_replay_and_returns_existing_event_id(): void
    {
        $guard = new QualityDefectFlowIdempotencyGuard;

        self::assertSame(
            '018f6f5a-4ca2-7a11-bf61-0242ac120002',
            $guard->exactReplay(
                '018f6f5a-4ca2-7a11-bf61-0242ac120002',
                str_repeat('a', 64),
                str_repeat('a', 64),
            ),
        );
    }

    public function test_rejects_conflicting_duplicate(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('quality_defect_flow_idempotency_conflict');

        (new QualityDefectFlowIdempotencyGuard)->exactReplay(
            '018f6f5a-4ca2-7a11-bf61-0242ac120002',
            str_repeat('a', 64),
            str_repeat('b', 64),
        );
    }
}
