<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\Services\CompletedWork\Reporting\AcceptedProduction\DTO\AcceptedProductionHistoryBoundary;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class AcceptedProductionHistoryBoundaryTest extends TestCase
{
    public function test_only_complete_local_days_and_post_checkpoint_sources_are_covered(): void
    {
        $boundary = new AcceptedProductionHistoryBoundary(
            new DateTimeImmutable('2026-08-06T10:15:30.123456Z'),
            100,
            200,
            300,
            400,
            500,
            str_repeat('a', 64),
        );

        self::assertSame('2026-08-07', $boundary->coverageStartDay(new DateTimeZone('Europe/Moscow')));
        self::assertFalse($boundary->coversOwner(200, new DateTimeImmutable('2026-08-07T00:00:00Z')));
        self::assertFalse($boundary->coversOwner(201, new DateTimeImmutable('2026-08-06T10:15:30Z')));
        self::assertTrue($boundary->coversOwner(201, new DateTimeImmutable('2026-08-06T10:15:31Z')));
        self::assertFalse($boundary->coversMember(300));
        self::assertTrue($boundary->coversMember(301));
        self::assertFalse($boundary->coversEvent(400, new DateTimeImmutable('2026-08-01T00:00:00Z')));
        self::assertTrue($boundary->coversEvent(401, new DateTimeImmutable('2026-08-06T10:15:31Z')));
        self::assertFalse($boundary->coversLedger(500, new DateTimeImmutable('2026-08-06T10:15:31Z')));
        self::assertTrue($boundary->coversLedger(501, new DateTimeImmutable('2026-08-06T10:15:31Z')));
    }

    public function test_boundary_identity_contains_every_frozen_source_watermark(): void
    {
        $boundary = new AcceptedProductionHistoryBoundary(
            new DateTimeImmutable('2026-08-06T10:15:30.123456Z'),
            10,
            20,
            30,
            40,
            50,
            str_repeat('b', 64),
        );

        self::assertSame([
            'completed_at' => '2026-08-06T10:15:30.123456Z',
            'performance_act_watermark_id' => 10,
            'owner_version_watermark_id' => 20,
            'owner_member_watermark_id' => 30,
            'event_watermark_id' => 40,
            'backfill_ledger_watermark_id' => 50,
            'source_hash' => str_repeat('b', 64),
        ], $boundary->canonicalIdentity());
    }
}
