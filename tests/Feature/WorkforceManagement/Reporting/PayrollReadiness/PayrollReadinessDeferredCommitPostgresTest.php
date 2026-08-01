<?php

declare(strict_types=1);

namespace Tests\Feature\WorkforceManagement\Reporting\PayrollReadiness;

use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\Enums\PayrollReadinessReason;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\Services\PayrollReadinessSnapshotBuilder;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Throwable;

#[Group('postgresql')]
final class PayrollReadinessDeferredCommitPostgresTest extends TestCase
{
    use DatabaseMigrations;

    private const PROBE_CONNECTION = 'payroll_readiness_commit_probe';

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

    protected function tearDown(): void
    {
        DB::purge(self::PROBE_CONNECTION);

        parent::tearDown();
    }

    public function test_database_rejects_unsealed_snapshot_on_actual_outer_commit(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $user = User::factory()->create();
        $capturedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $databaseTimestamp = $capturedAt->format('Y-m-d H:i:s.uP');

        DB::table('organization_user')->insert([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'is_owner' => true,
            'is_active' => true,
            'created_at' => $databaseTimestamp,
            'updated_at' => $databaseTimestamp,
        ]);
        $periodId = (int) DB::table('workforce_payroll_periods')->insertGetId([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'status' => 'draft',
            'created_by_user_id' => $user->id,
            'created_at' => $databaseTimestamp,
            'updated_at' => $databaseTimestamp,
        ]);
        $snapshot = $this->app->make(PayrollReadinessSnapshotBuilder::class)->blocked(
            organizationId: (int) $organization->id,
            periodId: $periodId,
            projectId: (int) $project->id,
            periodStart: '2026-07-01',
            periodEnd: '2026-07-31',
            actorUserId: (int) $user->id,
            evaluatedAt: $capturedAt,
            ownerSourceHash: str_repeat('a', 64),
            reason: PayrollReadinessReason::PERIOD_NOT_VALIDATED,
            sourceRows: [],
            validationIssues: [],
        );
        $payload = $snapshot->toPersistence();
        $payload['created_at'] = $databaseTimestamp;
        $probe = $this->probeConnection();
        $insertCompleted = false;
        $failure = null;

        try {
            $probe->beginTransaction();
            $probe->table('workforce_payroll_readiness_snapshots')->insert($payload);
            $insertCompleted = true;
            $probe->commit();
        } catch (Throwable $exception) {
            $failure = $exception;
        } finally {
            DB::purge(self::PROBE_CONNECTION);
        }

        self::assertTrue($insertCompleted, 'The deferred constraint must accept the INSERT before COMMIT.');
        if (! $failure instanceof PDOException) {
            self::fail('The actual PostgreSQL COMMIT must reject the unsealed snapshot.');
        }

        self::assertSame('23514', (string) ($failure->errorInfo[0] ?? $failure->getCode()));
        self::assertSame(0, DB::table('workforce_payroll_readiness_snapshots')->count());
    }

    private function probeConnection(): Connection
    {
        $configuration = config('database.connections.pgsql');
        self::assertIsArray($configuration);
        config()->set('database.connections.'.self::PROBE_CONNECTION, $configuration);
        DB::purge(self::PROBE_CONNECTION);

        return DB::connection(self::PROBE_CONNECTION);
    }
}
