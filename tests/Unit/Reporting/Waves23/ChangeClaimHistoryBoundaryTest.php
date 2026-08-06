<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\DTO\ChangeClaimHistoryBoundary;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ChangeClaimHistoryBoundaryTest extends TestCase
{
    public function test_only_complete_post_checkpoint_history_is_covered(): void
    {
        $boundary = new ChangeClaimHistoryBoundary(
            new DateTimeImmutable('2026-08-06T18:15:30.123456Z'),
            10,
            20,
            30,
            40,
            50,
            0,
            str_repeat('a', 64),
        );

        self::assertFalse($boundary->covers(new DateTimeImmutable('2026-08-06T18:15:30.123455Z')));
        self::assertTrue($boundary->covers(new DateTimeImmutable('2026-08-06T18:15:30.123456Z')));
        self::assertTrue($boundary->complete());

        $incomplete = new ChangeClaimHistoryBoundary(
            new DateTimeImmutable('2026-08-06T18:15:30.123456Z'),
            10,
            20,
            30,
            40,
            50,
            1,
            str_repeat('b', 64),
        );

        self::assertFalse($incomplete->complete());
        self::assertFalse($incomplete->covers(new DateTimeImmutable('2026-08-07T00:00:00Z')));
    }

    public function test_boundary_identity_contains_every_frozen_source_watermark(): void
    {
        $boundary = new ChangeClaimHistoryBoundary(
            new DateTimeImmutable('2026-08-06T18:15:30.123456Z'),
            10,
            20,
            30,
            40,
            50,
            2,
            str_repeat('c', 64),
        );

        self::assertSame([
            'completed_at' => '2026-08-06T18:15:30.123456Z',
            'change_request_watermark_id' => 10,
            'version_watermark_id' => 20,
            'workflow_event_watermark_id' => 30,
            'claim_link_watermark_id' => 40,
            'ledger_watermark_id' => 50,
            'unprojectable_legacy_count' => 2,
            'source_hash' => str_repeat('c', 64),
        ], $boundary->canonicalIdentity());
    }
}
