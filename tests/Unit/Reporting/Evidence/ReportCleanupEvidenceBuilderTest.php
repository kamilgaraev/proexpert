<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Evidence;

use App\BusinessModules\Core\Reporting\Application\Evidence\ReportCleanupEvidenceBuilder;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ReportCleanupEvidenceBuilderTest extends TestCase
{
    public function test_builds_the_six_closed_cleanup_checks_after_the_rollback_window(): void
    {
        $cutoverAt = new DateTimeImmutable('2026-07-01T00:00:00Z');
        $evidence = (new ReportCleanupEvidenceBuilder())->build(
            str_repeat('a', 40),
            str_repeat('b', 40),
            str_repeat('c', 40),
            str_repeat('d', 40),
            $cutoverAt,
            $cutoverAt->modify('+604800 seconds'),
            array_fill(0, 6, str_repeat('e', 64)),
        );

        self::assertSame('cleanup_verified', $evidence->status);
        self::assertSame(604800, $evidence->rollbackWindowSeconds);
        self::assertSame([
            'cleanup.cutover_pair',
            'cleanup.rollback_window',
            'cleanup.legacy_route_aliases',
            'cleanup.legacy_direct_callers',
            'cleanup.qg14_forbidden_symbols',
            'cleanup.policy_lock',
        ], $evidence->checkIds);
    }

    public function test_rejects_generation_before_the_full_rollback_window(): void
    {
        $cutoverAt = new DateTimeImmutable('2026-07-01T00:00:00Z');

        $this->expectException(InvalidArgumentException::class);
        (new ReportCleanupEvidenceBuilder())->build(
            str_repeat('a', 40), str_repeat('b', 40), str_repeat('c', 40), str_repeat('d', 40),
            $cutoverAt, $cutoverAt->modify('+604799 seconds'), array_fill(0, 6, str_repeat('e', 64)),
        );
    }
}
