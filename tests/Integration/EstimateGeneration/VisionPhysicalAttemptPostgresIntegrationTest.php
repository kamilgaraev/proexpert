<?php

declare(strict_types=1);

namespace Tests\Integration\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\UsageInvariantViolation;
use App\BusinessModules\Addons\EstimateGeneration\Vision\PhysicalAttempt\EloquentVisionPhysicalAttemptStore;
use DateTimeImmutable;
use Illuminate\Database\PostgresConnection;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\ExternalIntegrationGate;
use Throwable;

final class VisionPhysicalAttemptPostgresIntegrationTest extends TestCase
{
    public function test_concurrent_claim_has_one_owner_and_only_that_owner_can_start_the_wire(): void
    {
        [$first, $second, $schema, $configuration] = $this->fixture();
        $context = $this->context();
        $fingerprint = hash('sha256', 'concurrent-request');
        $owner = '11111111-1111-4111-8111-111111111111';
        $competitor = '22222222-2222-4222-8222-222222222222';
        $now = new DateTimeImmutable('2026-08-10T10:00:00+03:00');
        $process = null;
        $pipes = [];

        try {
            $store = new EloquentVisionPhysicalAttemptStore($first);
            $claimed = $store->claim($context, $fingerprint, $owner, $now, $now->modify('+1 minute'));
            self::assertSame($owner, $claimed->ownerToken);

            $first->beginTransaction();
            $first->table('estimate_generation_vision_physical_attempts')
                ->where('attempt_id', $context->attemptId)
                ->lockForUpdate()
                ->firstOrFail();

            [$process, $pipes] = $this->startClaimWorker(
                $configuration,
                $schema,
                $context,
                $fingerprint,
                $competitor,
                $now,
            );
            $workerPid = $this->waitForWorkerReady($process, $pipes);
            $this->waitUntilBlockedByOwner($second, $workerPid);

            $store->markWireStarted(
                $context->attemptId,
                $fingerprint,
                $owner,
                $now,
                $now->modify('+1 minute'),
            );
            $first->commit();

            $worker = $this->waitForWorkerResult($process, $pipes);
            $process = null;
            $pipes = [];
            self::assertSame('wire_started', $worker['state'] ?? null);
            self::assertSame($owner, $worker['owner_token'] ?? null);

            $this->expectException(UsageInvariantViolation::class);
            (new EloquentVisionPhysicalAttemptStore($second))->markWireStarted(
                $context->attemptId,
                $fingerprint,
                $competitor,
                $now,
                $now->modify('+1 minute'),
            );
        } finally {
            if ($first->transactionLevel() > 0) {
                $first->rollBack();
            }
            $this->stopWorker($process, $pipes);
            $this->cleanup($first, $second, $schema);
        }
    }

    public function test_only_stale_pre_wire_is_reclaimable_and_stale_wire_becomes_ambiguous(): void
    {
        [$first, $second, $schema] = $this->fixture();
        $fingerprint = hash('sha256', 'stale-request');
        $started = new DateTimeImmutable('2026-08-10T10:00:00+03:00');
        $stale = $this->context();
        $wire = $this->context('cccccccc-cccc-4ccc-8ccc-cccccccccccc');
        $owner = '11111111-1111-4111-8111-111111111111';
        $successor = '22222222-2222-4222-8222-222222222222';

        try {
            $firstStore = new EloquentVisionPhysicalAttemptStore($first);
            $secondStore = new EloquentVisionPhysicalAttemptStore($second);
            $firstStore->claim($stale, $fingerprint, $owner, $started, $started->modify('+1 second'));
            $taken = $secondStore->claim(
                $stale,
                $fingerprint,
                $successor,
                $started->modify('+2 seconds'),
                $started->modify('+1 minute'),
            );
            self::assertSame('pre_wire', $taken->state);
            self::assertSame($successor, $taken->ownerToken);

            $firstStore->claim($wire, $fingerprint, $owner, $started, $started->modify('+1 second'));
            $firstStore->markWireStarted(
                $wire->attemptId,
                $fingerprint,
                $owner,
                $started,
                $started->modify('+1 second'),
            );
            $ambiguous = $secondStore->claim(
                $wire,
                $fingerprint,
                $successor,
                $started->modify('+2 seconds'),
                $started->modify('+1 minute'),
            );

            self::assertSame('ambiguous', $ambiguous->state);
            self::assertSame('ambiguous', $second->table('estimate_generation_vision_physical_attempts')
                ->where('attempt_id', $wire->attemptId)->value('state'));
        } finally {
            $this->cleanup($first, $second, $schema);
        }
    }

    /** @return array{PostgresConnection, PostgresConnection, string, array<string, string>} */
    private function fixture(): array
    {
        if (! ExternalIntegrationGate::enabled('MOST_CI_POSTGRES_VISION_ATTEMPT_GATE')) {
            self::markTestSkipped('Dedicated PostgreSQL physical-attempt gate is CI-only.');
        }
        $configuration = [
            'dsn' => ExternalIntegrationGate::required('MOST_CI_POSTGRES_VISION_ATTEMPT_DSN'),
            'user' => ExternalIntegrationGate::required('MOST_CI_POSTGRES_VISION_ATTEMPT_USER'),
            'password' => ExternalIntegrationGate::required('MOST_CI_POSTGRES_VISION_ATTEMPT_PASSWORD'),
            'database_ack' => ExternalIntegrationGate::required('MOST_CI_POSTGRES_VISION_ATTEMPT_DATABASE_ACK'),
        ];
        if (! str_starts_with($configuration['dsn'], 'pgsql:')) {
            self::fail('MOST_CI_POSTGRES_VISION_ATTEMPT_DSN must be a PostgreSQL PDO DSN.');
        }

        $first = $this->connection($configuration);
        $second = $this->connection($configuration);
        self::assertNotSame($first->getPdo(), $second->getPdo());
        $database = (string) $first->selectOne('SELECT current_database() AS name')->name;
        if ($database !== $configuration['database_ack']
            || preg_match('/_(?:test|testing|contract)$/D', $database) !== 1) {
            self::fail('Exact disposable PostgreSQL test database acknowledgement is required.');
        }

        $schema = 'most_ci_vision_attempt_'.bin2hex(random_bytes(8));
        self::assertMatchesRegularExpression('/^most_ci_vision_attempt_[a-f0-9]{16}$/D', $schema);
        $created = false;
        try {
            $first->unprepared('CREATE SCHEMA "'.$schema.'"');
            $created = true;
            $this->setSearchPath($first, $schema);
            $this->setSearchPath($second, $schema);
            $first->unprepared(<<<'SQL'
            CREATE TABLE estimate_generation_vision_physical_attempts (
                attempt_id uuid PRIMARY KEY,
                request_fingerprint char(64) NOT NULL,
                organization_id bigint NOT NULL,
                project_id bigint NOT NULL,
                session_id bigint NOT NULL,
                document_id bigint NULL,
                page_id bigint NULL,
                unit_id bigint NULL,
                state varchar(24) NOT NULL,
                owner_token uuid NULL,
                lease_expires_at timestamptz NULL,
                wire_started_at timestamptz NULL,
                response_received_at timestamptz NULL,
                ambiguous_at timestamptz NULL,
                terminal_reason varchar(160) NULL,
                response_payload jsonb NULL,
                status varchar(24) NULL,
                http_code smallint NULL,
                duration_ms bigint NULL,
                reported_model varchar(160) NULL,
                price_snapshot jsonb NULL,
                usage_recorded boolean NOT NULL DEFAULT false,
                created_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL
            )
            SQL);
        } catch (Throwable $exception) {
            $second->unprepared('SET search_path TO public');
            $first->unprepared('SET search_path TO public');
            if ($created) {
                $first->unprepared('DROP SCHEMA "'.$schema.'" CASCADE');
            }
            $second->disconnect();
            $first->disconnect();

            throw $exception;
        }

        return [$first, $second, $schema, $configuration];
    }

    /** @param array<string, string> $configuration */
    private function connection(array $configuration): PostgresConnection
    {
        try {
            $pdo = new PDO(
                $configuration['dsn'],
                $configuration['user'],
                $configuration['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
        } catch (\Throwable $exception) {
            throw new RuntimeException('Configured PostgreSQL vision-attempt gate connection failed.', 0, $exception);
        }
        $connection = new PostgresConnection($pdo, $configuration['database_ack']);
        $connection->statement("SET statement_timeout TO '5000ms'");
        $connection->statement("SET lock_timeout TO '5000ms'");

        return $connection;
    }

    private function setSearchPath(PostgresConnection $connection, string $schema): void
    {
        if (preg_match('/^most_ci_vision_attempt_[a-f0-9]{16}$/D', $schema) !== 1) {
            throw new RuntimeException('Unsafe PostgreSQL integration schema.');
        }
        $connection->unprepared('SET search_path TO "'.$schema.'"');
    }

    /** @param array<string, string> $configuration @return array{resource, array<int, resource>} */
    private function startClaimWorker(
        array $configuration,
        string $schema,
        AiOperationContext $context,
        string $fingerprint,
        string $ownerToken,
        DateTimeImmutable $now,
    ): array {
        $payload = base64_encode(json_encode([
            'configuration' => $configuration,
            'schema' => $schema,
            'context' => get_object_vars($context),
            'fingerprint' => $fingerprint,
            'owner_token' => $ownerToken,
            'now' => $now->format(DATE_ATOM),
            'lease_expires_at' => $now->modify('+1 minute')->format(DATE_ATOM),
        ], JSON_THROW_ON_ERROR));
        $environment = getenv();
        if (! is_array($environment)) {
            $environment = [];
        }
        $environment['MOST_CI_VISION_ATTEMPT_WORKER_PAYLOAD'] = $payload;
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2).'/Support/EstimateGeneration/vision_physical_attempt_claim_worker.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 3),
            $environment,
        );
        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start PostgreSQL claim worker.');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return [$process, $pipes];
    }

    /** @param resource $process @param array<int, resource> $pipes */
    private function waitForWorkerReady($process, array $pipes): int
    {
        $deadline = microtime(true) + 5;
        $buffer = '';
        while (microtime(true) < $deadline) {
            $buffer .= stream_get_contents($pipes[1]);
            if (preg_match('/^READY:([1-9][0-9]*)\R/D', $buffer, $matches) === 1) {
                return (int) $matches[1];
            }
            if (! proc_get_status($process)['running']) {
                break;
            }
            usleep(20_000);
        }

        throw new RuntimeException('PostgreSQL claim worker did not reach the concurrency barrier: '.stream_get_contents($pipes[2]));
    }

    private function waitUntilBlockedByOwner(PostgresConnection $observer, int $workerPid): void
    {
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $row = $observer->selectOne('SELECT cardinality(pg_blocking_pids(?)) AS blockers', [$workerPid]);
            if ((int) ($row->blockers ?? 0) > 0) {
                self::addToAssertionCount(1);

                return;
            }
            usleep(20_000);
        }

        self::fail('Concurrent PostgreSQL claim never blocked on the owner transaction.');
    }

    /** @param resource $process @param array<int, resource> $pipes @return array<string, mixed> */
    private function waitForWorkerResult($process, array $pipes): array
    {
        $deadline = microtime(true) + 7;
        $stdout = '';
        while (microtime(true) < $deadline) {
            $stdout .= stream_get_contents($pipes[1]);
            $status = proc_get_status($process);
            if (! $status['running']) {
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                if (($status['exitcode'] ?? 1) !== 0) {
                    throw new RuntimeException('PostgreSQL claim worker failed: '.$stderr);
                }
                $decoded = json_decode(trim($stdout), true, 16, JSON_THROW_ON_ERROR);
                if (! is_array($decoded)) {
                    throw new RuntimeException('PostgreSQL claim worker returned an invalid result.');
                }

                return $decoded;
            }
            usleep(20_000);
        }

        throw new RuntimeException('PostgreSQL claim worker exceeded its strict timeout.');
    }

    /** @param resource|null $process @param array<int, resource> $pipes */
    private function stopWorker($process, array $pipes): void
    {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        if (is_resource($process)) {
            $status = proc_get_status($process);
            if ($status['running']) {
                proc_terminate($process);
            }
            proc_close($process);
        }
    }

    private function cleanup(PostgresConnection $first, PostgresConnection $second, string $schema): void
    {
        if (preg_match('/^most_ci_vision_attempt_[a-f0-9]{16}$/D', $schema) !== 1) {
            throw new RuntimeException('Refusing to clean an unsafe PostgreSQL integration schema.');
        }
        $second->unprepared('SET search_path TO public');
        $first->unprepared('SET search_path TO public');
        $first->unprepared('DROP SCHEMA "'.$schema.'" CASCADE');
        $second->disconnect();
        $first->disconnect();
    }

    private function context(string $attemptId = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'): AiOperationContext
    {
        return new AiOperationContext(
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            $attemptId,
            1,
            2,
            3,
            'understand_documents',
            'vision',
            1,
            4,
            5,
            6,
        );
    }
}
