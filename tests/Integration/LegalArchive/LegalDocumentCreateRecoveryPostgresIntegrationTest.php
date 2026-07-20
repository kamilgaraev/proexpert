<?php

declare(strict_types=1);

namespace Tests\Integration\LegalArchive;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

final class LegalDocumentCreateRecoveryPostgresIntegrationTest extends TestCase
{
    private Capsule $database;

    private ConnectionInterface $first;

    private ConnectionInterface $second;

    private string $schema;

    protected function setUp(): void
    {
        parent::setUp();

        $dsn = getenv('LEGAL_DOCUMENT_PG_TEST_DSN');
        if (
            getenv('LEGAL_ARCHIVE_PG_CREATE_RECOVERY_CONTRACT') !== '1'
            || getenv('LEGAL_DOCUMENT_PG_TEST_ALLOW_DDL') !== '1'
            || ! is_string($dsn)
            || $dsn === ''
        ) {
            self::markTestSkipped('Dedicated PostgreSQL create recovery contract database is not enabled.');
        }

        $config = $this->connectionConfig($dsn);
        $this->database = new Capsule;
        $this->database->addConnection($config, 'recovery_first');
        $this->database->addConnection($config, 'recovery_second');
        $this->database->setAsGlobal();
        $container = new Container;
        $container->instance('db', $this->database->getDatabaseManager());
        Facade::setFacadeApplication($container);
        $this->database->getDatabaseManager()->setDefaultConnection('recovery_first');
        $this->first = $this->database->getConnection('recovery_first');
        $this->second = $this->database->getConnection('recovery_second');

        $database = (string) $this->first->selectOne('SELECT current_database() AS name')->name;
        if (preg_match('/(?:_test|_testing)$/D', $database) !== 1) {
            self::markTestSkipped('PostgreSQL database name must end with _test or _testing.');
        }

        $this->schema = 'legal_create_recovery_it_'.bin2hex(random_bytes(6));
        $this->first->statement("CREATE SCHEMA {$this->schema}");
        $this->first->statement("SET search_path TO {$this->schema}");
        $this->second->statement("SET search_path TO {$this->schema}");
        $this->installSchema();
    }

    protected function tearDown(): void
    {
        if (isset($this->first, $this->schema) && str_starts_with($this->schema, 'legal_create_recovery_it_')) {
            $this->first->statement("DROP SCHEMA {$this->schema} CASCADE");
        }
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        parent::tearDown();
    }

    public function test_two_stale_reclaim_workers_allow_one_claim_and_one_in_progress(): void
    {
        $documentId = $this->document('pending', 'old-attempt', now()->subMinute(), 'retry_upload');

        $firstClaimed = $this->claim($this->first, $documentId, 'first-attempt');
        $secondClaimed = $this->claim($this->second, $documentId, 'second-attempt');

        self::assertSame(1, $firstClaimed);
        self::assertSame(0, $secondClaimed);
        $row = $this->first->table('legal_archive_documents')->where('id', $documentId)->first();
        self::assertSame('first-attempt', $row?->source_create_attempt_token);
        self::assertGreaterThan(now()->timestamp, strtotime((string) $row?->source_create_lease_expires_at));
    }

    public function test_stale_attempt_token_cannot_finalize_or_fail_reclaimed_operation(): void
    {
        $documentId = $this->document('pending', 'old-attempt', now()->subMinute(), 'retry_upload');
        self::assertSame(1, $this->claim($this->first, $documentId, 'new-attempt'));

        $staleFinalize = $this->first->table('legal_archive_documents')
            ->where('id', $documentId)
            ->where('source_create_status', 'pending')
            ->where('source_create_attempt_token', 'old-attempt')
            ->update(['source_create_status' => 'completed', 'source_create_attempt_token' => null]);
        $staleFail = $this->second->table('legal_archive_documents')
            ->where('id', $documentId)
            ->where('source_create_status', 'pending')
            ->where('source_create_attempt_token', 'old-attempt')
            ->update(['source_create_status' => 'failed', 'source_create_attempt_token' => null]);

        self::assertSame(0, $staleFinalize);
        self::assertSame(0, $staleFail);
        self::assertSame('new-attempt', $this->first->table('legal_archive_documents')->where('id', $documentId)->value('source_create_attempt_token'));
    }

    public function test_ready_version_selects_retry_finalize_without_inserting_new_version(): void
    {
        $documentId = $this->document('pending', 'old-attempt', now()->subMinute(), 'retry_upload');
        $this->first->table('legal_archive_document_versions')->insert([
            'document_id' => $documentId,
            'processing_status' => 'ready',
        ]);
        $before = $this->first->table('legal_archive_document_versions')->where('document_id', $documentId)->count();
        $hasReady = $this->first->table('legal_archive_document_versions')
            ->where('document_id', $documentId)
            ->where('processing_status', 'ready')
            ->exists();
        self::assertTrue($hasReady);

        $updated = $this->first->table('legal_archive_documents')
            ->where('id', $documentId)
            ->where('source_create_lease_expires_at', '<=', now())
            ->update([
                'source_create_attempt_token' => 'finalize-attempt',
                'source_create_lease_expires_at' => now()->addMinutes(30),
                'source_create_retry_action' => $hasReady ? 'retry_finalize' : 'retry_upload',
            ]);

        self::assertSame(1, $updated);
        self::assertSame('retry_finalize', $this->first->table('legal_archive_documents')->where('id', $documentId)->value('source_create_retry_action'));
        self::assertSame($before, $this->first->table('legal_archive_document_versions')->where('document_id', $documentId)->count());
    }

    public function test_completed_lost_response_replay_does_not_mutate_document_or_versions(): void
    {
        $documentId = $this->document('completed', null, null, null);
        $this->first->table('legal_archive_document_versions')->insert([
            'document_id' => $documentId,
            'processing_status' => 'ready',
        ]);
        $beforeDocument = (array) $this->first->table('legal_archive_documents')->where('id', $documentId)->first();
        $beforeVersions = $this->first->table('legal_archive_document_versions')->where('document_id', $documentId)->count();

        $claimed = $this->claim($this->second, $documentId, 'replay-attempt');

        self::assertSame(0, $claimed);
        self::assertSame($beforeDocument, (array) $this->first->table('legal_archive_documents')->where('id', $documentId)->first());
        self::assertSame($beforeVersions, $this->first->table('legal_archive_document_versions')->where('document_id', $documentId)->count());
    }

    public function test_audit_failure_rolls_back_then_best_effort_cas_preserves_recovery_state(): void
    {
        $documentId = $this->document('pending', 'owned-attempt', now()->addMinutes(30), 'retry_finalize');

        try {
            $this->first->transaction(function () use ($documentId): void {
                $this->first->table('legal_archive_documents')
                    ->where('id', $documentId)
                    ->where('source_create_attempt_token', 'owned-attempt')
                    ->update([
                        'source_create_status' => 'failed',
                        'source_create_attempt_token' => null,
                        'source_create_lease_expires_at' => null,
                    ]);
                throw new \RuntimeException('audit unavailable');
            });
        } catch (\RuntimeException) {
        }
        self::assertSame('pending', $this->first->table('legal_archive_documents')->where('id', $documentId)->value('source_create_status'));

        $fallback = $this->second->table('legal_archive_documents')
            ->where('id', $documentId)
            ->where('source_create_status', 'pending')
            ->where('source_create_attempt_token', 'owned-attempt')
            ->update([
                'source_create_status' => 'failed',
                'source_create_attempt_token' => null,
                'source_create_lease_expires_at' => null,
                'source_create_retry_action' => 'retry_finalize',
            ]);

        self::assertSame(1, $fallback);
        self::assertSame('failed', $this->first->table('legal_archive_documents')->where('id', $documentId)->value('source_create_status'));
    }

    private function claim(ConnectionInterface $connection, int $documentId, string $token): int
    {
        return $connection->table('legal_archive_documents')
            ->where('id', $documentId)
            ->whereIn('source_create_status', ['pending', 'failed'])
            ->where(function ($query): void {
                $query->whereNull('source_create_lease_expires_at')
                    ->orWhere('source_create_lease_expires_at', '<=', now());
            })
            ->update([
                'source_create_status' => 'pending',
                'source_create_attempt_token' => $token,
                'source_create_heartbeat_at' => now(),
                'source_create_lease_expires_at' => now()->addMinutes(30),
                'source_create_attempt_count' => $connection->raw('source_create_attempt_count + 1'),
            ]);
    }

    private function document(string $status, ?string $token, mixed $leaseExpiresAt, ?string $retryAction): int
    {
        return (int) $this->first->table('legal_archive_documents')->insertGetId([
            'source_create_status' => $status,
            'source_create_attempt_token' => $token,
            'source_create_attempt_count' => $token === null ? 1 : 2,
            'source_create_heartbeat_at' => now()->subMinute(),
            'source_create_lease_expires_at' => $leaseExpiresAt,
            'source_create_retry_action' => $retryAction,
        ]);
    }

    private function installSchema(): void
    {
        $this->first->unprepared(<<<'SQL'
CREATE TABLE legal_archive_documents (
    id bigserial PRIMARY KEY,
    source_create_status text NOT NULL,
    source_create_attempt_token text NULL,
    source_create_attempt_count integer NOT NULL DEFAULT 0,
    source_create_heartbeat_at timestamptz NULL,
    source_create_lease_expires_at timestamptz NULL,
    source_create_retry_action text NULL
);
CREATE TABLE legal_archive_document_versions (
    id bigserial PRIMARY KEY,
    document_id bigint NOT NULL REFERENCES legal_archive_documents(id),
    processing_status text NOT NULL
);
SQL);
    }

    private function connectionConfig(string $dsn): array
    {
        $parts = [];
        foreach (explode(';', preg_replace('/^pgsql:/', '', $dsn) ?? '') as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, null);
            if ($key !== '' && $value !== null) {
                $parts[$key] = $value;
            }
        }

        return [
            'driver' => 'pgsql', 'host' => $parts['host'] ?? '127.0.0.1', 'port' => $parts['port'] ?? '5432',
            'database' => $parts['dbname'] ?? '', 'username' => getenv('LEGAL_DOCUMENT_PG_TEST_USER') ?: null,
            'password' => getenv('LEGAL_DOCUMENT_PG_TEST_PASSWORD') ?: null, 'charset' => 'utf8',
            'prefix' => '', 'schema' => 'public', 'sslmode' => $parts['sslmode'] ?? 'prefer',
        ];
    }
}
