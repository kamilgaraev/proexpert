<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\WaveOne;

use App\BusinessModules\Core\Reporting\Temporal\TemporalOwnerFactResolver;
use App\BusinessModules\Core\Reporting\Temporal\TemporalOwnerFactLease;
use DateTimeImmutable;
use DomainException;
use Illuminate\Database\ConnectionInterface;
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

    #[Test]
    public function production_schema_has_deterministic_sequence_append_only_guards_and_all_r21_owners(): void
    {
        $root = dirname(__DIR__, 4);
        $migration = file_get_contents(
            $root.'/app/BusinessModules/Features/WorkforceManagement/migrations/'
            .'2026_07_26_000150_create_workforce_report_projections.php',
        );
        $laborAdapter = file_get_contents(
            $root.'/app/BusinessModules/Features/TimeTracking/Reporting/Infrastructure/'
            .'DatabaseProjectLaborCostAdapter.php',
        );

        self::assertIsString($migration);
        self::assertIsString($laborAdapter);
        self::assertStringContainsString("\$table->unsignedBigInteger('sequence')", $migration);
        self::assertStringContainsString('workforce_owner_facts_sequence_unique', $migration);
        self::assertStringContainsString('workforce_report_owner_facts_append_only', $migration);
        self::assertStringContainsString('workforce_report_owner_fact_eligibility_append_only', $migration);
        self::assertStringContainsString("'project_schedules'", $migration);
        self::assertStringContainsString("'project_schedules'", $laborAdapter);
    }

    #[Test]
    public function lease_attempts_to_drop_every_shadow_table_and_aggregates_cleanup_failure(): void
    {
        $attempts = [];
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('statement')->willReturnCallback(
            static function (string $sql) use (&$attempts): bool {
                $attempts[] = $sql;
                if (count($attempts) === 1) {
                    throw new \RuntimeException('first drop failed');
                }

                return true;
            },
        );
        $lease = new TemporalOwnerFactLease($connection, ['projects', 'project_schedules']);

        try {
            $lease->release();
            self::fail('Cleanup failure must be reported');
        } catch (DomainException $exception) {
            self::assertStringContainsString(
                'REPORT_TEMPORAL_OWNER_FACT_CLEANUP_FAILED',
                $exception->getMessage(),
            );
        }

        self::assertCount(2, $attempts);
    }
}
