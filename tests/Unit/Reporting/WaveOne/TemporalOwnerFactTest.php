<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\WaveOne;

use App\BusinessModules\Core\Reporting\Temporal\TemporalOwnerFactResolver;
use DateTimeImmutable;
use DomainException;
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

    #[Test]
    public function resolver_materializes_only_latest_scoped_payloads_at_as_of(): void
    {
        $resolver = new TemporalOwnerFactResolver;
        $facts = [
            (object) [
                'source_id' => 7,
                'project_id' => 20,
                'recorded_at' => '2026-07-10T08:00:00+00:00',
                'sequence' => 1,
                'operation' => 'upsert',
                'payload' => ['id' => 7, 'project_id' => 20, 'name' => 'До изменения'],
            ],
            (object) [
                'source_id' => 7,
                'project_id' => 20,
                'recorded_at' => '2026-07-10T12:00:00+00:00',
                'sequence' => 2,
                'operation' => 'upsert',
                'payload' => ['id' => 7, 'project_id' => 20, 'name' => 'После изменения'],
            ],
            (object) [
                'source_id' => 8,
                'project_id' => 21,
                'recorded_at' => '2026-07-10T08:00:00+00:00',
                'sequence' => 3,
                'operation' => 'upsert',
                'payload' => ['id' => 8, 'project_id' => 21],
            ],
            (object) [
                'source_id' => 8,
                'project_id' => 20,
                'recorded_at' => '2026-07-10T14:00:00+00:00',
                'sequence' => 4,
                'operation' => 'upsert',
                'payload' => ['id' => 8, 'project_id' => 20],
            ],
        ];

        self::assertSame(
            [7 => ['id' => 7, 'project_id' => 20, 'name' => 'До изменения']],
            $resolver->payloadsAt(
                $facts,
                new DateTimeImmutable('2026-07-10T10:00:00+00:00'),
                [20],
            ),
        );
        self::assertSame(
            [],
            $resolver->payloadsAt(
                $facts,
                new DateTimeImmutable('2026-07-10T15:00:00+00:00'),
                [21],
            ),
        );
    }

    #[Test]
    public function resolver_rejects_a_backfill_gap_instead_of_accepting_live_state(): void
    {
        $resolver = new TemporalOwnerFactResolver;

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('REPORT_TEMPORAL_OWNER_FACT_GAP');

        $resolver->assertExactState(
            [],
            [7 => ['id' => 7, 'project_id' => 20]],
        );
    }
}
