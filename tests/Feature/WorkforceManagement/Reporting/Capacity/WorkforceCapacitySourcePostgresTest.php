<?php

declare(strict_types=1);

namespace Tests\Feature\WorkforceManagement\Reporting\Capacity;

use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityCohortKey;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityPolicyDefinition;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Services\EloquentWorkforceCapacitySnapshotStore;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Services\WorkforceCapacitySnapshotBuilder;
use App\Models\Organization;
use App\Models\Project;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

#[Group('postgresql')]
final class WorkforceCapacitySourcePostgresTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (getenv('WORKFORCE_CAPACITY_POSTGRES_TESTS') !== '1') {
            $this->markTestSkipped(
                'Set WORKFORCE_CAPACITY_POSTGRES_TESTS=1 to run isolated PostgreSQL workforce-capacity tests.',
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

    public function test_exact_replay_is_idempotent_and_changed_source_creates_new_snapshot(): void
    {
        $fixture = $this->fixture();
        $store = new EloquentWorkforceCapacitySnapshotStore;
        $snapshot = $this->snapshot($fixture);
        $cursor = str_repeat('a', 64);

        $store->appendBatch('r19-pg-replay-001', null, $cursor, [$snapshot]);
        $store->appendBatch('r19-pg-replay-001', null, $cursor, [$snapshot]);
        $store->completeCapture('r19-pg-replay-001', $fixture['organization_id'], $cursor, 1, 1);

        self::assertSame(1, DB::table('workforce_capacity_snapshots')->count());
        self::assertSame($snapshot->itemCount, DB::table('workforce_capacity_snapshot_items')->count());
        self::assertNotNull(DB::table('workforce_capacity_snapshots')->value('sealed_at'));

        $changed = $fixture;
        $changed['source']['assignments'][0]['rate'] = '0.7500';
        $changedSnapshot = $this->snapshot($changed);
        self::assertNotSame($snapshot->sourceHash, $changedSnapshot->sourceHash);
        $store->appendBatch('r19-pg-changed-001', null, str_repeat('b', 64), [$changedSnapshot]);

        self::assertSame(2, DB::table('workforce_capacity_snapshots')->count());
        self::assertSame(2, DB::table('workforce_capacity_snapshots')->distinct()->count('source_hash'));
    }

    public function test_postgresql_guards_reject_mutation_and_nested_personal_data(): void
    {
        $fixture = $this->fixture();
        $snapshot = $this->snapshot($fixture);
        (new EloquentWorkforceCapacitySnapshotStore)->appendBatch(
            'r19-pg-guards-001',
            null,
            str_repeat('c', 64),
            [$snapshot],
        );
        $snapshotId = (int) DB::table('workforce_capacity_snapshots')->value('id');
        $itemId = (int) DB::table('workforce_capacity_snapshot_items')->value('id');

        $this->assertSqlState($this->captureQueryException(static function () use ($snapshotId): void {
            DB::table('workforce_capacity_snapshots')->where('id', $snapshotId)->update([
                'assigned_fte' => '99.0000',
            ]);
        }), '55000');
        $this->assertSqlState($this->captureQueryException(static function () use ($itemId): void {
            DB::table('workforce_capacity_snapshot_items')->where('id', $itemId)->delete();
        }), '55000');

        $containsRestricted = DB::selectOne(
            'SELECT workforce_capacity_json_has_forbidden(?::jsonb) AS rejected',
            [json_encode(['safe' => ['nested' => ['salary' => '100000.00']]], JSON_THROW_ON_ERROR)],
        );
        self::assertTrue((bool) $containsRestricted?->rejected);
    }

    public function test_null_project_bucket_is_persisted_without_deriving_a_project(): void
    {
        $fixture = $this->fixture(projectBucket: false);
        $snapshot = $this->snapshot($fixture);

        (new EloquentWorkforceCapacitySnapshotStore)->appendBatch(
            'r19-pg-null-bucket-001',
            null,
            str_repeat('d', 64),
            [$snapshot],
        );

        self::assertNull(DB::table('workforce_capacity_snapshots')->value('project_id'));
        self::assertSame(
            0,
            DB::table('workforce_capacity_snapshot_items')->whereNotNull('project_id')->count(),
        );
    }

    public function test_snapshot_and_owner_change_roll_back_together(): void
    {
        $fixture = $this->fixture();
        $snapshot = $this->snapshot($fixture);

        try {
            DB::transaction(static function () use ($fixture, $snapshot): void {
                DB::table('workforce_staff_units')
                    ->where('id', $fixture['staff_unit_id'])
                    ->update(['headcount' => '2.00']);
                (new EloquentWorkforceCapacitySnapshotStore)->appendBatch(
                    'r19-pg-owner-rollback-001',
                    null,
                    str_repeat('e', 64),
                    [$snapshot],
                );

                throw new RuntimeException('workforce_capacity_owner_rollback_sentinel');
            });
            self::fail('The owner transaction must fail.');
        } catch (RuntimeException $exception) {
            self::assertSame('workforce_capacity_owner_rollback_sentinel', $exception->getMessage());
        }

        self::assertSame(
            '1.00',
            (string) DB::table('workforce_staff_units')->where('id', $fixture['staff_unit_id'])->value('headcount'),
        );
        self::assertSame(0, DB::table('workforce_capacity_snapshots')->count());
        self::assertSame(0, DB::table('workforce_capacity_capture_requests')->count());
    }

    public function test_database_recomputes_policy_hash_and_rejects_cross_organization_project_lineage(): void
    {
        $fixture = $this->fixture();
        $snapshot = $this->snapshot($fixture);
        $payload = $snapshot->toPersistence();
        unset($payload['semantic_label']);
        $payload += [
            'capture_mutation_id' => 'r19-pg-direct-guard-001',
            'capture_cursor' => str_repeat('f', 64),
            'sealed_at' => null,
        ];

        $badPolicy = $payload;
        $badPolicy['policy_hash'] = str_repeat('0', 64);
        $this->assertSqlState($this->captureQueryException(static function () use ($badPolicy): void {
            DB::table('workforce_capacity_snapshots')->insert($badPolicy);
        }), '23514');

        $foreignOrganization = Organization::factory()->create(['workforce_timezone' => 'Europe/Moscow']);
        $foreignProject = Project::factory()->create(['organization_id' => $foreignOrganization->id]);
        $badLineage = $payload;
        $badLineage['project_id'] = (int) $foreignProject->id;
        $this->assertSqlState($this->captureQueryException(static function () use ($badLineage): void {
            DB::table('workforce_capacity_snapshots')->insert($badLineage);
        }), '23514');

        self::assertSame(0, DB::table('workforce_capacity_snapshots')->count());
    }

    private function fixture(bool $projectBucket = true): array
    {
        $organization = Organization::factory()->create(['workforce_timezone' => 'Europe/Moscow']);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $now = $this->utcNow()->format('Y-m-d H:i:s.uP');
        $departmentId = (int) DB::table('workforce_departments')->insertGetId([
            'organization_id' => $organization->id,
            'code' => 'R19-PG-DEP',
            'name' => 'Capacity contract',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $positionId = (int) DB::table('workforce_positions')->insertGetId([
            'organization_id' => $organization->id,
            'code' => 'R19-PG-POS',
            'name' => 'Capacity contract',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $scheduleId = (int) DB::table('workforce_work_schedules')->insertGetId([
            'organization_id' => $organization->id,
            'code' => 'R19-PG-SCHEDULE',
            'name' => 'Explicit weekly schedule',
            'schedule_type' => 'weekly',
            'hours_per_day' => '8.00',
            'week_pattern' => json_encode([
                '1' => '8.00', '2' => '8.00', '3' => '8.00', '4' => '8.00', '5' => '8.00',
                '6' => '0.00', '7' => '0.00',
            ], JSON_THROW_ON_ERROR),
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $staffUnitId = (int) DB::table('workforce_staff_units')->insertGetId([
            'organization_id' => $organization->id,
            'department_id' => $departmentId,
            'position_id' => $positionId,
            'code' => 'R19-PG-UNIT',
            'headcount' => '1.00',
            'rate' => '1.0000',
            'base_salary' => null,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $employeeId = (int) DB::table('workforce_employees')->insertGetId([
            'organization_id' => $organization->id,
            'personnel_number' => 'R19-PG-EMP',
            'last_name' => 'Capacity',
            'first_name' => 'Contract',
            'employment_status' => 'active',
            'hire_date' => '2026-01-01',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $assignmentProjectId = $projectBucket ? (int) $project->id : null;
        $assignmentId = (int) DB::table('workforce_employee_assignments')->insertGetId([
            'organization_id' => $organization->id,
            'employee_id' => $employeeId,
            'staff_unit_id' => $staffUnitId,
            'department_id' => $departmentId,
            'position_id' => $positionId,
            'project_id' => $assignmentProjectId,
            'work_schedule_id' => $scheduleId,
            'rate' => '1.0000',
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'organization_id' => (int) $organization->id,
            'project_id' => $assignmentProjectId,
            'staff_unit_id' => $staffUnitId,
            'source' => [
                'staff_unit' => [
                    'id' => $staffUnitId,
                    'organization_id' => (int) $organization->id,
                    'department_id' => $departmentId,
                    'position_id' => $positionId,
                    'headcount' => '1.00',
                    'rate' => '1.0000',
                    'valid_from' => '2026-01-01',
                    'valid_to' => null,
                    'is_active' => true,
                    'deleted_at' => null,
                ],
                'assignments' => [[
                    'id' => $assignmentId,
                    'organization_id' => (int) $organization->id,
                    'employee_id' => $employeeId,
                    'staff_unit_id' => $staffUnitId,
                    'project_id' => $assignmentProjectId,
                    'work_schedule_id' => $scheduleId,
                    'rate' => '1.0000',
                    'valid_from' => '2026-01-01',
                    'valid_to' => null,
                    'status' => 'active',
                    'deleted_at' => null,
                ]],
                'schedules' => [[
                    'id' => $scheduleId,
                    'organization_id' => (int) $organization->id,
                    'schedule_type' => 'weekly',
                    'week_pattern' => [
                        '1' => '8.00', '2' => '8.00', '3' => '8.00', '4' => '8.00', '5' => '8.00',
                        '6' => '0.00', '7' => '0.00',
                    ],
                    'is_active' => true,
                    'deleted_at' => null,
                ]],
                'schedule_days' => [],
                'absences' => [],
                'business_trips' => [],
                'employee_lifecycle' => [[
                    'id' => $employeeId,
                    'employee_id' => $employeeId,
                    'employment_status' => 'active',
                    'dismissal_date' => null,
                ]],
                'gaps' => [],
            ],
        ];
    }

    private function snapshot(array $fixture): mixed
    {
        return (new WorkforceCapacitySnapshotBuilder(WorkforceCapacityPolicyDefinition::v1('Europe/Moscow')))->build(
            key: new WorkforceCapacityCohortKey(
                $fixture['organization_id'],
                '2026-08-01',
                '2026-08-01',
                $fixture['staff_unit_id'],
                $fixture['project_id'],
            ),
            captureKind: 'change_capture',
            capturedAt: $this->utcNow(),
            actorUserId: null,
            serviceActor: 'workforce-owner',
            source: $fixture['source'],
        );
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

    private function utcNow(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
