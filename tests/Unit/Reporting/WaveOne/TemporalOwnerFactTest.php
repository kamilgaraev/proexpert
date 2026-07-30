<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\WaveOne;

use App\BusinessModules\Core\Reporting\Temporal\TemporalOwnerFactResolver;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TemporalOwnerFactTest extends TestCase
{
    #[Test]
    public function resolver_returns_latest_append_only_owner_fact_not_after_as_of(): void
    {
        $resolver = new TemporalOwnerFactResolver;
        $facts = [
            (object) [
                'recorded_at' => '2026-07-10T08:00:00+00:00',
                'sequence' => 1,
                'operation' => 'upsert',
                'payload' => ['name' => 'До изменения'],
            ],
            (object) [
                'recorded_at' => '2026-07-10T12:00:00+00:00',
                'sequence' => 2,
                'operation' => 'upsert',
                'payload' => ['name' => 'После изменения'],
            ],
        ];

        self::assertSame(
            ['name' => 'До изменения'],
            $resolver->payloadAt($facts, new DateTimeImmutable('2026-07-10T10:00:00+00:00')),
        );
        self::assertSame(
            ['name' => 'После изменения'],
            $resolver->payloadAt($facts, new DateTimeImmutable('2026-07-10T13:00:00+00:00')),
        );
    }

    #[Test]
    public function resolver_treats_delete_as_absent_and_reports_missing_history(): void
    {
        $resolver = new TemporalOwnerFactResolver;
        $facts = [
            (object) [
                'recorded_at' => '2026-07-10T08:00:00+00:00',
                'sequence' => 1,
                'operation' => 'upsert',
                'payload' => ['id' => 7],
            ],
            (object) [
                'recorded_at' => '2026-07-10T12:00:00+00:00',
                'sequence' => 2,
                'operation' => 'delete',
                'payload' => ['id' => 7],
            ],
        ];

        self::assertNull(
            $resolver->payloadAt($facts, new DateTimeImmutable('2026-07-10T13:00:00+00:00')),
        );
        self::assertFalse(
            $resolver->hasCoverageAt($facts, new DateTimeImmutable('2026-07-10T07:00:00+00:00')),
        );
    }
}
