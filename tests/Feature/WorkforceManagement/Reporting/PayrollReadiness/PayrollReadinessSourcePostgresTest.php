<?php

declare(strict_types=1);

namespace Tests\Feature\WorkforceManagement\Reporting\PayrollReadiness;

use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\DTO\PayrollReadinessPeriodIdentity;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\Enums\PayrollReadinessReason;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\Services\PayrollReadinessOwnerSnapshotRecorder;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

#[Group('postgresql')]
final class PayrollReadinessSourcePostgresTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (getenv('PAYROLL_READINESS_POSTGRES_TESTS') !== '1') {
            $this->markTestSkipped(
                'Set PAYROLL_READINESS_POSTGRES_TESTS=1 to run isolated PostgreSQL payroll-readiness tests.',
            );
        }

        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Requires an explicitly configured isolated PostgreSQL database.');
        }

        $database = config('database.connections.pgsql.database');
        if (! is_string($database) || preg_match('/_(?:test|testing)$/D', $database) !== 1) {
            $this->markTestSkipped('PostgreSQL database name must end with _test or _testing.');
        }

        self::assertSame('pgsql', DB::connection()->getDriverName());
    }

    public function test_exact_replay_is_idempotent_and_distinct_attempt_is_preserved(): void
    {
        $fixture = $this->fixture(withBlockingIssue: true);
        $recorder = $this->recorder();
        $identity = $this->identity($fixture);
        $evaluatedAt = new DateTimeImmutable('2026-08-01T10:15:00+00:00');

        $recorder->recordBlocked(
            $identity,
            $fixture['user_id'],
            $evaluatedAt,
            str_repeat('a', 64),
            PayrollReadinessReason::VALIDATION_BLOCKERS,
        );
        $recorder->recordBlocked(
            $identity,
            $fixture['user_id'],
            $evaluatedAt,
            str_repeat('a', 64),
            PayrollReadinessReason::VALIDATION_BLOCKERS,
        );

        self::assertSame(1, DB::table('workforce_payroll_readiness_snapshots')->count());
        self::assertSame(7, DB::table('workforce_payroll_readiness_snapshot_items')->count());

        $recorder->recordBlocked(
            $identity,
            $fixture['user_id'],
            new DateTimeImmutable('2026-08-01T10:20:00+00:00'),
            str_repeat('a', 64),
            PayrollReadinessReason::VALIDATION_BLOCKERS,
        );

        self::assertSame(2, DB::table('workforce_payroll_readiness_snapshots')->count());
        self::assertSame(14, DB::table('workforce_payroll_readiness_snapshot_items')->count());
        self::assertSame(
            1,
            DB::table('workforce_payroll_readiness_snapshots')->distinct()->count('state_hash'),
        );
        self::assertSame(
            2,
            DB::table('workforce_payroll_readiness_snapshots')->distinct()->count('source_hash'),
        );

        $snapshotId = (int) DB::table('workforce_payroll_readiness_snapshots')->value('id');
        $itemId = (int) DB::table('workforce_payroll_readiness_snapshot_items')->value('id');
        $this->assertSqlState($this->captureQueryException(static function () use ($snapshotId): void {
            DB::table('workforce_payroll_readiness_snapshots')->where('id', $snapshotId)->update([
                'reason_code' => 'source_changed',
            ]);
        }), '55000');
        $this->assertSqlState($this->captureQueryException(static function () use ($itemId): void {
            DB::table('workforce_payroll_readiness_snapshot_items')->where('id', $itemId)->delete();
        }), '55000');
    }

    public function test_database_rejects_item_appended_after_snapshot_was_completed(): void
    {
        $fixture = $this->fixture(withBlockingIssue: true);
        $this->recorder()->recordBlocked(
            $this->identity($fixture),
            $fixture['user_id'],
            new DateTimeImmutable('2026-08-01T10:15:00+00:00'),
            str_repeat('a', 64),
            PayrollReadinessReason::VALIDATION_BLOCKERS,
        );

        $snapshot = (array) DB::table('workforce_payroll_readiness_snapshots')->first();
        $sourceItem = (array) DB::table('workforce_payroll_readiness_snapshot_items')
            ->where('payroll_readiness_snapshot_id', $snapshot['id'])
            ->where('source_type', 'payroll_source_row')
            ->first();

        $exception = $this->captureQueryException(static function () use ($snapshot, $sourceItem): void {
            unset($sourceItem['id']);
            $sourceItem['position'] = (int) $snapshot['item_count'] + 1;
            DB::table('workforce_payroll_readiness_snapshot_items')->insert($sourceItem);
        });

        $this->assertSqlState($exception, '55000');
        self::assertSame(
            (int) $snapshot['item_count'],
            DB::table('workforce_payroll_readiness_snapshot_items')
                ->where('payroll_readiness_snapshot_id', $snapshot['id'])
                ->count(),
        );
    }

    public function test_database_rejects_values_outside_closed_v1_snapshot_contract(): void
    {
        $fixture = $this->fixture(withBlockingIssue: false);
        $snapshot = $this->recorder()->recordBlocked(
            $this->identity($fixture),
            $fixture['user_id'],
            new DateTimeImmutable('2026-08-01T10:15:00+00:00'),
            str_repeat('a', 64),
            PayrollReadinessReason::SOURCE_CHANGED,
        );
        $payload = $snapshot->toPersistence();
        $payload['evaluated_at'] = $snapshot->evaluatedAt;
        $payload['created_at'] = $snapshot->evaluatedAt;
        $payload['source_hash'] = str_repeat('f', 64);
        $payload['schema_version'] = 'payroll-readiness-source.v2';

        $exception = $this->captureQueryException(static function () use ($payload): void {
            DB::table('workforce_payroll_readiness_snapshots')->insert($payload);
        });

        $this->assertSqlState($exception, '23514');
        self::assertSame(1, DB::table('workforce_payroll_readiness_snapshots')->count());
    }

    public function test_database_rejects_reason_that_contradicts_full_evidence_set_at_single_seal(): void
    {
        $fixture = $this->fixture(withBlockingIssue: false);
        $snapshot = $this->recorder()->recordBlocked(
            $this->identity($fixture),
            $fixture['user_id'],
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            str_repeat('a', 64),
            PayrollReadinessReason::SOURCE_CHANGED,
        );
        $exception = $this->captureQueryException(static function () use ($snapshot): void {
            $payload = $snapshot->toPersistence();
            $payload['evaluated_at'] = $snapshot->evaluatedAt;
            $payload['created_at'] = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $payload['reason_code'] = PayrollReadinessReason::SOURCE_EMPTY->value;
            $payload['source_hash'] = str_repeat('f', 64);
            $payload['state_hash'] = str_repeat('e', 64);
            $snapshotId = (int) DB::table('workforce_payroll_readiness_snapshots')->insertGetId($payload);
            $items = [];

            foreach ($snapshot->items() as $position => $item) {
                $items[] = [
                    ...$item->toPersistence($position + 1),
                    'organization_id' => $snapshot->organizationId,
                    'payroll_period_id' => $snapshot->periodId,
                    'payroll_readiness_snapshot_id' => $snapshotId,
                    'created_at' => new DateTimeImmutable('now', new DateTimeZone('UTC')),
                ];
            }

            DB::table('workforce_payroll_readiness_snapshot_items')->insert($items);
            DB::table('workforce_payroll_readiness_snapshots')
                ->where('id', $snapshotId)
                ->update(['sealed_at' => new DateTimeImmutable('now', new DateTimeZone('UTC'))]);
        });

        $this->assertSqlState($exception, '23514');
    }

    public function test_production_sized_snapshot_uses_one_full_set_seal_and_constant_time_late_append_guard(): void
    {
        $fixture = $this->fixture(withBlockingIssue: false);
        $baseRow = (array) DB::table('workforce_payroll_source_rows')
            ->where('payroll_period_id', $fixture['period_id'])
            ->first();
        unset($baseRow['id']);
        $baseRow['timesheet_entry_id'] = null;
        $rows = [];

        for ($index = 0; $index < 1500; $index++) {
            $rows[] = $baseRow;
        }

        foreach (array_chunk($rows, 500) as $batch) {
            DB::table('workforce_payroll_source_rows')->insert($batch);
        }

        $sealUpdates = 0;
        DB::listen(static function (QueryExecuted $query) use (&$sealUpdates): void {
            if (str_contains($query->sql, 'workforce_payroll_readiness_snapshots')
                && str_contains($query->sql, 'sealed_at')
                && str_starts_with(strtolower(ltrim($query->sql)), 'update')) {
                $sealUpdates++;
            }
        });

        $this->recorder()->recordBlocked(
            $this->identity($fixture),
            $fixture['user_id'],
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            str_repeat('a', 64),
            PayrollReadinessReason::SOURCE_CHANGED,
        );

        $snapshot = (array) DB::table('workforce_payroll_readiness_snapshots')->first();
        self::assertSame(1506, (int) $snapshot['item_count']);
        self::assertNotNull($snapshot['sealed_at']);
        self::assertSame(1, $sealUpdates);
        self::assertSame(
            1506,
            DB::table('workforce_payroll_readiness_snapshot_items')
                ->where('payroll_readiness_snapshot_id', $snapshot['id'])
                ->count(),
        );

        $lateItem = (array) DB::table('workforce_payroll_readiness_snapshot_items')
            ->where('payroll_readiness_snapshot_id', $snapshot['id'])
            ->where('source_type', 'payroll_source_row')
            ->first();
        unset($lateItem['id']);
        $lateItem['position'] = 1507;
        $this->assertSqlState($this->captureQueryException(static function () use ($lateItem): void {
            DB::table('workforce_payroll_readiness_snapshot_items')->insert($lateItem);
        }), '55000');
    }

    public function test_database_rejects_cross_project_period_lineage(): void
    {
        $fixture = $this->fixture(withBlockingIssue: false);
        $foreignProject = Project::factory()->create(['organization_id' => $fixture['organization_id']]);

        $exception = $this->captureQueryException(function () use ($fixture, $foreignProject): void {
            $this->recorder()->recordBlocked(
                new PayrollReadinessPeriodIdentity(
                    $fixture['organization_id'],
                    $fixture['period_id'],
                    (int) $foreignProject->id,
                    '2026-07-01',
                    '2026-07-31',
                ),
                $fixture['user_id'],
                new DateTimeImmutable('2026-08-01T10:15:00+00:00'),
                str_repeat('a', 64),
                PayrollReadinessReason::SOURCE_CHANGED,
            );
        });

        $this->assertSqlState($exception, '23514');
        self::assertSame(0, DB::table('workforce_payroll_readiness_snapshots')->count());
    }

    public function test_database_rejects_actor_without_active_organization_membership(): void
    {
        $fixture = $this->fixture(withBlockingIssue: false);
        $foreignUser = User::factory()->create();

        $exception = $this->captureQueryException(function () use ($fixture, $foreignUser): void {
            $this->recorder()->recordBlocked(
                $this->identity($fixture),
                (int) $foreignUser->id,
                new DateTimeImmutable('2026-08-01T10:15:00+00:00'),
                str_repeat('a', 64),
                PayrollReadinessReason::SOURCE_CHANGED,
            );
        });

        $this->assertSqlState($exception, '23514');
        self::assertSame(0, DB::table('workforce_payroll_readiness_snapshots')->count());
    }

    public function test_locked_snapshot_requires_exact_period_hash_actor_and_utc_time(): void
    {
        $fixture = $this->fixture(withBlockingIssue: false);
        $lockedAt = new DateTimeImmutable('2026-08-01T10:15:00+00:00');
        $lockedHash = str_repeat('b', 64);
        DB::table('workforce_payroll_periods')->where('id', $fixture['period_id'])->update([
            'status' => 'locked',
            'source_hash' => $lockedHash,
            'locked_by_user_id' => $fixture['user_id'],
            'locked_at' => $lockedAt,
        ]);

        $this->recorder()->recordLocked(
            $this->identity($fixture),
            $fixture['user_id'],
            $lockedAt,
            $lockedHash,
        );

        self::assertSame(1, DB::table('workforce_payroll_readiness_snapshots')->count());

        $exception = $this->captureQueryException(function () use ($fixture, $lockedAt): void {
            $this->recorder()->recordLocked(
                $this->identity($fixture),
                $fixture['user_id'],
                $lockedAt,
                str_repeat('c', 64),
            );
        });

        $this->assertSqlState($exception, '23514');
        self::assertSame(1, DB::table('workforce_payroll_readiness_snapshots')->count());
    }

    public function test_snapshot_and_owner_lock_roll_back_together(): void
    {
        $fixture = $this->fixture(withBlockingIssue: false);
        $lockedAt = new DateTimeImmutable('2026-08-01T10:15:00+00:00');
        $lockedHash = str_repeat('d', 64);

        try {
            DB::transaction(function () use ($fixture, $lockedAt, $lockedHash): void {
                DB::table('workforce_payroll_periods')->where('id', $fixture['period_id'])->update([
                    'status' => 'locked',
                    'source_hash' => $lockedHash,
                    'locked_by_user_id' => $fixture['user_id'],
                    'locked_at' => $lockedAt,
                ]);
                $this->recorder()->recordLocked(
                    $this->identity($fixture),
                    $fixture['user_id'],
                    $lockedAt,
                    $lockedHash,
                );

                throw new RuntimeException('payroll_readiness_owner_rollback_sentinel');
            });
            self::fail('The owner transaction must fail.');
        } catch (RuntimeException $exception) {
            self::assertSame('payroll_readiness_owner_rollback_sentinel', $exception->getMessage());
        }

        self::assertSame('validated', DB::table('workforce_payroll_periods')
            ->where('id', $fixture['period_id'])
            ->value('status'));
        self::assertSame(0, DB::table('workforce_payroll_readiness_snapshots')->count());
    }

    private function fixture(bool $withBlockingIssue): array
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $user = User::factory()->create();
        $now = '2026-08-01 10:00:00+00';
        DB::table('organization_user')->insert([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'is_owner' => true,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $employeeId = (int) DB::table('workforce_employees')->insertGetId([
            'organization_id' => $organization->id,
            'personnel_number' => 'R22-PG-EMP',
            'last_name' => 'Contract',
            'first_name' => 'Test',
            'employment_status' => 'active',
            'hire_date' => '2026-01-01',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $periodId = (int) DB::table('workforce_payroll_periods')->insertGetId([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'status' => 'validated',
            'created_by_user_id' => $user->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $sourceRowId = (int) DB::table('workforce_payroll_source_rows')->insertGetId([
            'organization_id' => $organization->id,
            'payroll_period_id' => $periodId,
            'employee_id' => $employeeId,
            'project_id' => $project->id,
            'work_date' => '2026-07-10',
            'source_type' => 'timesheet_hours',
            'hours' => 8,
            'amount' => 100,
            'payload' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($withBlockingIssue) {
            DB::table('workforce_payroll_validation_issues')->insert([
                'organization_id' => $organization->id,
                'payroll_period_id' => $periodId,
                'severity' => 'blocking',
                'issue_code' => 'missing_assignment',
                'message' => 'fixture-only',
                'entity_type' => 'payroll_source_row',
                'entity_id' => $sourceRowId,
                'employee_id' => $employeeId,
                'project_id' => $project->id,
                'payload' => json_encode([], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return [
            'organization_id' => (int) $organization->id,
            'project_id' => (int) $project->id,
            'user_id' => (int) $user->id,
            'period_id' => $periodId,
        ];
    }

    private function identity(array $fixture): PayrollReadinessPeriodIdentity
    {
        return new PayrollReadinessPeriodIdentity(
            $fixture['organization_id'],
            $fixture['period_id'],
            $fixture['project_id'],
            '2026-07-01',
            '2026-07-31',
        );
    }

    private function recorder(): PayrollReadinessOwnerSnapshotRecorder
    {
        return $this->app->make(PayrollReadinessOwnerSnapshotRecorder::class);
    }

    private function captureQueryException(callable $operation): QueryException
    {
        try {
            DB::transaction(static function () use ($operation): void {
                $operation();
            });
        } catch (QueryException $exception) {
            return $exception;
        }

        self::fail('Expected PostgreSQL to reject the operation.');
    }

    private function assertSqlState(QueryException $exception, string $expected): void
    {
        self::assertSame($expected, (string) ($exception->errorInfo[0] ?? $exception->getCode()));
    }
}
