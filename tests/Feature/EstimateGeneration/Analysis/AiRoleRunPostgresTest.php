<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Analysis;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunResult;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\EloquentAiRoleRunRepository;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Role\AiAnalysisRole;
use DateTimeImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class AiRoleRunPostgresTest extends TestCase
{
    public function createApplication(): Application
    {
        $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    public function test_exact_replay_is_immutable_and_source_versions_are_isolated(): void
    {
        [$connection, $schema] = $this->fixture();

        try {
            $repository = new EloquentAiRoleRunRepository($connection, leaseSeconds: 60);
            $input = $this->input('sha256:'.str_repeat('a', 64));
            $claim = $repository->claim($input, '11111111-1111-4111-8111-111111111111');
            self::assertSame('owned', $claim->disposition);

            $result = new AiRoleRunResult(
                payload: ['claims' => [['code' => 'wall_observed']]],
                physicalAttemptId: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            );
            $repository->startPhysicalAttempt(
                $claim->runId,
                $claim->ownerUuid,
                'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            );
            $repository->startPhysicalAttempt($claim->runId, $claim->ownerUuid, $result->physicalAttemptId);
            $repository->complete($claim->runId, $claim->ownerUuid, $result);
            self::assertSame($result->physicalAttemptId, $connection->table('estimate_generation_ai_role_runs')
                ->where('id', $claim->runId)->value('physical_attempt_id'));

            $replay = $repository->claim($input, '22222222-2222-4222-8222-222222222222');
            self::assertSame('replay', $replay->disposition);
            self::assertSame($result->payload, $replay->result?->payload);

            $replacement = $repository->claim(
                $this->input('sha256:'.str_repeat('b', 64)),
                '33333333-3333-4333-8333-333333333333',
            );
            self::assertSame('owned', $replacement->disposition);
            self::assertNotSame($claim->runId, $replacement->runId);
        } finally {
            $this->cleanup($connection, $schema);
        }
    }

    public function test_active_claim_is_busy_stale_prewire_is_reclaimed_and_stale_postwire_is_ambiguous(): void
    {
        [$connection, $schema] = $this->fixture();

        try {
            $repository = new EloquentAiRoleRunRepository($connection, leaseSeconds: 60);
            $input = $this->input('sha256:'.str_repeat('c', 64));
            $owner = '11111111-1111-4111-8111-111111111111';
            $successor = '22222222-2222-4222-8222-222222222222';
            $claim = $repository->claim($input, $owner);
            self::assertSame('busy', $repository->claim($input, $successor)->disposition);

            $connection->table('estimate_generation_ai_role_runs')->where('id', $claim->runId)->update([
                'lease_expires_at' => new DateTimeImmutable('-1 minute'),
            ]);
            $taken = $repository->claim($input, $successor);
            self::assertSame('owned', $taken->disposition);
            self::assertSame($successor, $taken->ownerUuid);

            $repository->startPhysicalAttempt(
                $taken->runId,
                $successor,
                'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            );
            $connection->table('estimate_generation_ai_role_runs')->where('id', $taken->runId)->update([
                'lease_expires_at' => new DateTimeImmutable('-1 minute'),
            ]);
            $ambiguous = $repository->claim(
                $input,
                '33333333-3333-4333-8333-333333333333',
            );
            self::assertSame('ambiguous', $ambiguous->disposition);
            self::assertSame('ambiguous', $connection->table('estimate_generation_ai_role_runs')
                ->where('id', $taken->runId)->value('status'));
        } finally {
            $this->cleanup($connection, $schema);
        }
    }

    public function test_current_lookup_is_tenant_scoped_and_completed_payload_is_bounded_by_postgresql(): void
    {
        [$connection, $schema] = $this->fixture();

        try {
            $repository = new EloquentAiRoleRunRepository($connection, leaseSeconds: 60);
            $input = $this->input('sha256:'.str_repeat('d', 64));
            $claim = $repository->claim($input, '11111111-1111-4111-8111-111111111111');
            $repository->complete($claim->runId, $claim->ownerUuid, new AiRoleRunResult(
                payload: ['claims' => []],
                physicalAttemptId: null,
            ));

            self::assertNotNull($repository->loadCurrent($input));
            self::assertNull($repository->loadCurrent($this->input(
                'sha256:'.str_repeat('d', 64),
                organizationId: 11,
            )));

            $this->expectException(QueryException::class);
            $connection->table('estimate_generation_ai_role_runs')->where('id', $claim->runId)->update([
                'result_payload' => json_encode(['value' => str_repeat('x', AiRoleRunResult::MAX_PAYLOAD_BYTES + 1)], JSON_THROW_ON_ERROR),
            ]);
        } finally {
            $this->cleanup($connection, $schema);
        }
    }

    public function test_concurrent_claims_are_serialized_to_one_owner(): void
    {
        [$connection, $schema] = $this->fixture();
        $competitor = null;

        try {
            $repository = new EloquentAiRoleRunRepository($connection, leaseSeconds: 60);
            $input = $this->input('sha256:'.str_repeat('e', 64));
            $owner = '11111111-1111-4111-8111-111111111111';
            $claim = $repository->claim($input, $owner);

            $connection->beginTransaction();
            self::assertNotNull($connection->table('estimate_generation_ai_role_runs')
                ->where('id', $claim->runId)
                ->lockForUpdate()
                ->first());
            $competitor = $this->nativeConnection($connection, $schema);
            $pidResult = pg_query($competitor, 'SELECT pg_backend_pid() AS pid');
            self::assertNotFalse($pidResult);
            $workerPid = (int) pg_fetch_result($pidResult, 0, 'pid');
            self::assertTrue(pg_send_query(
                $competitor,
                "BEGIN; SELECT owner_uuid::text FROM estimate_generation_ai_role_runs WHERE id = {$claim->runId} FOR UPDATE; COMMIT;",
            ));
            $this->waitUntilBlockedByOwner($connection, $workerPid);
            self::assertTrue(pg_connection_busy($competitor));
            $connection->commit();

            $observedOwner = null;
            $deadline = microtime(true) + 5;
            while (pg_connection_busy($competitor) && microtime(true) < $deadline) {
                usleep(20_000);
            }
            self::assertFalse(pg_connection_busy($competitor));
            while (($result = pg_get_result($competitor)) !== false) {
                self::assertNotSame(PGSQL_FATAL_ERROR, pg_result_status($result));
                if (pg_num_fields($result) > 0 && pg_num_rows($result) > 0) {
                    $observedOwner = pg_fetch_result($result, 0, 'owner_uuid');
                }
            }
            self::assertSame($owner, $observedOwner);

            $busy = $repository->claim(
                $input,
                '22222222-2222-4222-8222-222222222222',
            );
            self::assertSame('busy', $busy->disposition);
            self::assertSame($owner, $busy->ownerUuid);
            self::assertSame($claim->runId, $busy->runId);
            self::assertSame(1, $connection->table('estimate_generation_ai_role_runs')->count());
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            if ($competitor instanceof \PgSql\Connection) {
                pg_cancel_query($competitor);
                pg_close($competitor);
            }
            $this->cleanup($connection, $schema);
        }
    }

    /** @return array{PostgresConnection, string} */
    private function fixture(): array
    {
        $connection = DB::connection();
        self::assertInstanceOf(PostgresConnection::class, $connection);
        self::assertSame('pgsql', $connection->getDriverName());
        self::assertTrue(
            $connection->getDatabaseName() === 'most_backend_testing'
                || ($connection->getDatabaseName() === 'most_ai_estimator_contract'
                    && getenv('RUN_ESTIMATE_GENERATION_POSTGRES_CONTRACT') === '1'),
        );
        $connection->statement("SET statement_timeout TO '5000ms'");
        $connection->statement("SET lock_timeout TO '5000ms'");
        $schema = 'most_ci_ai_role_run_'.bin2hex(random_bytes(8));
        $connection->unprepared('CREATE SCHEMA "'.$schema.'"');
        $connection->unprepared('SET search_path TO "'.$schema.'"');
        $connection->unprepared(<<<'SQL'
            CREATE TABLE organizations (id bigint PRIMARY KEY);
            CREATE TABLE projects (id bigint PRIMARY KEY);
            CREATE TABLE estimate_generation_sessions (id bigint PRIMARY KEY);
            CREATE TABLE estimate_generation_documents (id bigint PRIMARY KEY);
            CREATE TABLE estimate_generation_document_pages (id bigint PRIMARY KEY);
            CREATE TABLE estimate_generation_vision_physical_attempts (attempt_id uuid PRIMARY KEY);
            SQL);
        $migration = require app_path('BusinessModules/Addons/EstimateGeneration/migrations/2026_08_14_000100_create_estimate_generation_ai_role_runs.php');
        $migration->up();
        $connection->table('organizations')->insert([['id' => 10], ['id' => 11]]);
        $connection->table('projects')->insert(['id' => 20]);
        $connection->table('estimate_generation_sessions')->insert(['id' => 30]);
        $connection->table('estimate_generation_documents')->insert(['id' => 40]);
        $connection->table('estimate_generation_document_pages')->insert(['id' => 50]);
        $connection->table('estimate_generation_vision_physical_attempts')->insert([
            ['attempt_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'],
            ['attempt_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'],
        ]);

        return [$connection, $schema];
    }

    private function nativeConnection(PostgresConnection $connection, string $schema): \PgSql\Connection
    {
        $configuration = $connection->getConfig();
        $native = pg_connect(sprintf(
            'host=%s port=%s dbname=%s user=%s password=%s connect_timeout=5',
            (string) ($configuration['host'] ?? ''),
            (string) ($configuration['port'] ?? '5432'),
            (string) ($configuration['database'] ?? ''),
            (string) ($configuration['username'] ?? ''),
            (string) ($configuration['password'] ?? ''),
        ), PGSQL_CONNECT_FORCE_NEW);
        if (! $native instanceof \PgSql\Connection) {
            throw new RuntimeException('Unable to open PostgreSQL competitor connection.');
        }
        if (! pg_query($native, "SET statement_timeout TO '5000ms'; SET lock_timeout TO '5000ms'; SET search_path TO \"{$schema}\"")) {
            throw new RuntimeException('Unable to initialize PostgreSQL competitor connection.');
        }

        return $native;
    }

    private function waitUntilBlockedByOwner(PostgresConnection $connection, int $workerPid): void
    {
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $row = $connection->selectOne('SELECT cardinality(pg_blocking_pids(?)) AS blockers', [$workerPid]);
            if ((int) ($row->blockers ?? 0) > 0) {
                self::addToAssertionCount(1);

                return;
            }
            usleep(20_000);
        }

        self::fail('Concurrent PostgreSQL AI role claim never blocked on the owner transaction.');
    }

    private function cleanup(PostgresConnection $connection, string $schema): void
    {
        if (preg_match('/^most_ci_ai_role_run_[a-f0-9]{16}$/D', $schema) !== 1) {
            return;
        }
        $connection->unprepared('SET search_path TO public');
        $connection->unprepared('DROP SCHEMA "'.$schema.'" CASCADE');
    }

    private function input(string $sourceVersion, int $organizationId = 10): AiRoleRunInput
    {
        return new AiRoleRunInput(
            organizationId: $organizationId,
            projectId: 20,
            sessionId: 30,
            documentId: 40,
            pageId: 50,
            subjectType: 'document_page',
            subjectId: '50',
            subjectVersion: $sourceVersion,
            role: AiAnalysisRole::LiteralObserver,
            model: 'pinned-multimodal-model',
            promptContractVersion: 'observer-literal:v1',
            inputFingerprint: hash('sha256', 'render|'.$sourceVersion),
        );
    }
}
