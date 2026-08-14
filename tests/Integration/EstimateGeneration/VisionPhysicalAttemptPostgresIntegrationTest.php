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

    public function test_http_200_raw_and_parsed_envelope_replay_without_a_second_physical_attempt(): void
    {
        [$first, $second, $schema] = $this->fixture();
        $context = $this->context();
        $fingerprint = hash('sha256', 'durable-http-200');
        $owner = '11111111-1111-4111-8111-111111111111';
        $competitor = '22222222-2222-4222-8222-222222222222';
        $now = new DateTimeImmutable('2026-08-10T10:00:00+03:00');
        $parsed = ['model' => 'openai/gpt-5.6-luna', 'choices' => [['finish_reason' => 'stop']]];
        $raw = json_encode($parsed, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        try {
            $store = new EloquentVisionPhysicalAttemptStore($first);
            $store->claim($context, $fingerprint, $owner, $now, $now->modify('+1 minute'));
            $store->markWireStarted($context->attemptId, $fingerprint, $owner, $now, $now->modify('+1 minute'));
            $store->storeResponse(
                $context->attemptId,
                $fingerprint,
                $owner,
                ['raw_body_base64' => base64_encode($raw), 'parsed_envelope' => $parsed],
                'response_received',
                200,
                43_000,
                null,
                ['status' => 'available', 'currency' => 'USD'],
            );
            $store->markUsageRecorded($context->attemptId, $fingerprint);

            $replay = (new EloquentVisionPhysicalAttemptStore($second))->claim(
                $context,
                $fingerprint,
                $competitor,
                $now->modify('+2 minutes'),
                $now->modify('+3 minutes'),
            );

            self::assertSame('completed', $replay->state);
            self::assertSame(200, $replay->httpCode);
            self::assertSame(43_000, $replay->durationMs);
            self::assertTrue($replay->usageRecorded);
            self::assertSame($parsed, $replay->responsePayload['parsed_envelope'] ?? null);
            self::assertSame($raw, base64_decode((string) ($replay->responsePayload['raw_body_base64'] ?? ''), true));
            self::assertSame(1, $first->table('estimate_generation_vision_physical_attempts')->count());
        } finally {
            $this->cleanup($first, $second, $schema);
        }
    }

    public function test_explicit_retry_lineages_claim_distinct_physical_attempts_for_one_logical_request(): void
    {
        [$first, $second, $schema] = $this->fixture();
        $fingerprint = hash('sha256', 'document-173-page-3-logical-request');
        $firstContext = $this->context(
            '09e235ee-65ad-5971-b60d-fa0cf925514e',
            '6a2fe0cd-49af-4fbc-855f-f989c9ce842e',
        );
        $retryContext = $this->context(
            'dddddddd-dddd-5ddd-8ddd-dddddddddddd',
            '069e6374-9f47-4d10-9b56-a38000559921',
        );
        $now = new DateTimeImmutable('2026-08-14T21:06:24Z');

        try {
            (new EloquentVisionPhysicalAttemptStore($first))->claim(
                $firstContext,
                $fingerprint,
                '11111111-1111-4111-8111-111111111111',
                $now,
                $now->modify('+1 minute'),
            );
            (new EloquentVisionPhysicalAttemptStore($second))->claim(
                $retryContext,
                $fingerprint,
                '22222222-2222-4222-8222-222222222222',
                $now,
                $now->modify('+1 minute'),
            );

            self::assertSame(2, $first->table('estimate_generation_vision_physical_attempts')->count());
            self::assertSame(
                [$firstContext->processingLineageId, $retryContext->processingLineageId],
                $first->table('estimate_generation_vision_physical_attempts')
                    ->orderBy('created_at')
                    ->pluck('processing_lineage_id')
                    ->all(),
            );
            self::assertSame(1, $first->table('estimate_generation_vision_physical_attempts')
                ->where('attempt_id', $retryContext->attemptId)
                ->where('logical_request_fingerprint', $fingerprint)
                ->count());
        } finally {
            $this->cleanup($first, $second, $schema);
        }
    }

    /** @return array{PostgresConnection, PostgresConnection, string, array<string, string>} */
    private function fixture(): array
    {
        $configuration = getenv('RUN_ESTIMATE_GENERATION_POSTGRES_CONTRACT') === '1'
            ? [
                'dsn' => sprintf('pgsql:host=%s;port=%s;dbname=%s', getenv('DB_HOST'), getenv('DB_PORT'), getenv('DB_DATABASE')),
                'user' => (string) getenv('DB_USERNAME'),
                'password' => (string) getenv('DB_PASSWORD'),
                'database_ack' => (string) getenv('DB_DATABASE'),
            ]
            : $this->externalConfiguration();
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
                logical_request_fingerprint char(64) NULL,
                processing_lineage_id uuid NULL,
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
        stream_set_blocking($pipes[1], true);
        stream_set_blocking($pipes[2], false);

        return [$process, $pipes];
    }

    /** @param resource $process @param array<int, resource> $pipes */
    private function waitForWorkerReady($process, array $pipes): int
    {
        $line = fgets($pipes[1]);
        if (is_string($line) && preg_match('/^READY:([1-9][0-9]*)\R/D', $line, $matches) === 1) {
            return (int) $matches[1];
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
        $line = fgets($pipes[1]);
        $decoded = is_string($line) ? json_decode(trim($line), true) : null;
        if (is_array($decoded)) {
            $this->stopWorker($process, $pipes);

            return $decoded;
        }

        throw new RuntimeException('PostgreSQL claim worker failed: '.stream_get_contents($pipes[2]));
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
                $deadline = microtime(true) + 1;
                do {
                    usleep(20_000);
                    $status = proc_get_status($process);
                } while ($status['running'] && microtime(true) < $deadline);
                if ($status['running']) {
                    throw new RuntimeException('PostgreSQL claim worker did not terminate.');
                }
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

    /** @return array<string, string> */
    private function externalConfiguration(): array
    {
        if (! ExternalIntegrationGate::enabled('MOST_CI_POSTGRES_VISION_ATTEMPT_GATE')) {
            self::markTestSkipped('Dedicated PostgreSQL physical-attempt gate is CI-only.');
        }

        return [
            'dsn' => ExternalIntegrationGate::required('MOST_CI_POSTGRES_VISION_ATTEMPT_DSN'),
            'user' => ExternalIntegrationGate::required('MOST_CI_POSTGRES_VISION_ATTEMPT_USER'),
            'password' => ExternalIntegrationGate::required('MOST_CI_POSTGRES_VISION_ATTEMPT_PASSWORD'),
            'database_ack' => ExternalIntegrationGate::required('MOST_CI_POSTGRES_VISION_ATTEMPT_DATABASE_ACK'),
        ];
    }

    private function context(
        string $attemptId = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
        ?string $processingLineageId = null,
    ): AiOperationContext {
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
            $processingLineageId,
        );
    }
}
