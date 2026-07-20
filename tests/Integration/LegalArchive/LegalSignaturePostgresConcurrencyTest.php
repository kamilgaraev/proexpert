<?php

declare(strict_types=1);

namespace Tests\Integration\LegalArchive;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

final class LegalSignaturePostgresConcurrencyTest extends TestCase
{
    private Capsule $database;

    private ConnectionInterface $first;

    private string $schema;

    protected function setUp(): void
    {
        parent::setUp();
        $dsn = getenv('LEGAL_DOCUMENT_PG_TEST_DSN');
        if (getenv('LEGAL_ARCHIVE_PG_SIGNATURE_CONCURRENCY') !== '1'
            || ! is_string($dsn) || $dsn === '' || getenv('LEGAL_DOCUMENT_PG_TEST_ALLOW_DDL') !== '1'
            || ! function_exists('pcntl_fork') || ! function_exists('pcntl_waitpid')) {
            self::markTestSkipped('Dedicated PostgreSQL signature concurrency contract database is not enabled.');
        }
        $this->database = new Capsule;
        $this->database->addConnection($this->connectionConfig($dsn), 'signature_first');
        $this->database->addConnection($this->connectionConfig($dsn), 'signature_second');
        $this->database->setAsGlobal();
        $container = new Container;
        $container->instance('db', $this->database->getDatabaseManager());
        Facade::setFacadeApplication($container);
        $this->database->getDatabaseManager()->setDefaultConnection('signature_first');
        $this->first = $this->database->getConnection('signature_first');
        $database = (string) $this->first->selectOne('SELECT current_database() AS name')->name;
        if (preg_match('/(?:_test|_testing)$/D', $database) !== 1) {
            self::markTestSkipped('PostgreSQL database name must end with _test or _testing.');
        }
        $this->schema = 'legal_signature_it_'.bin2hex(random_bytes(6));
        $this->first->statement("CREATE SCHEMA {$this->schema}");
        foreach (['signature_first', 'signature_second'] as $connection) {
            $this->database->getConnection($connection)->statement("SET search_path TO {$this->schema}");
        }
        $this->installBaseSchema();
        (require dirname(__DIR__, 3).'/database/migrations/2026_07_19_000280_allow_fenced_legal_document_version_rescan.php')->up();
        foreach (['000600_create_legal_document_signatures', '000610_create_legal_document_signature_indexes', '000620_add_legal_document_signature_constraints', '000630_validate_legal_document_signature_constraints'] as $suffix) {
            (require dirname(__DIR__, 3)."/database/migrations/2026_07_19_{$suffix}.php")->up();
        }
        $this->seedAggregate();
    }

    protected function tearDown(): void
    {
        if (isset($this->first, $this->schema) && str_starts_with($this->schema, 'legal_signature_it_')) {
            $this->first->statement("DROP SCHEMA {$this->schema} CASCADE");
        }
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        parent::tearDown();
    }

    public function test_parallel_same_command_creates_only_one_signature_request(): void
    {
        $gate = 'signature-race-'.bin2hex(random_bytes(6));
        $this->first->select('SELECT pg_advisory_lock(hashtextextended(?, 0))', [$gate]);
        $children = [];
        for ($worker = 0; $worker < 2; $worker++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                $manager = $this->database->getDatabaseManager();
                $manager->disconnect('signature_first');
                $manager->disconnect('signature_second');
                $connection = $manager->connection($worker === 0 ? 'signature_first' : 'signature_second');
                $connection->statement("SET search_path TO {$this->schema}");
                $connection->select('SELECT pg_advisory_lock_shared(hashtextextended(?, 0))', [$gate]);
                try {
                    $connection->table('legal_signature_requests')->insert($this->requestRow());
                    $exit = 0;
                } catch (\Throwable $error) {
                    $exit = (string) $error->getCode() === '23505' ? 10 : 20;
                }
                $connection->select('SELECT pg_advisory_unlock_shared(hashtextextended(?, 0))', [$gate]);
                exit($exit);
            }
            if ($pid < 0) {
                throw new \RuntimeException('legal_signature_race_fork_failed');
            }
            $children[] = $pid;
        }
        $this->first->select('SELECT pg_advisory_unlock(hashtextextended(?, 0))', [$gate]);
        $outcomes = [];
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            self::assertTrue(pcntl_wifexited($status));
            $outcomes[] = pcntl_wexitstatus($status);
        }
        sort($outcomes);
        self::assertSame([0, 10], $outcomes);
        self::assertSame(1, $this->first->table('legal_signature_requests')->count());
    }

    public function test_index_descriptor_drift_and_raw_evidence_mutation_fail_closed(): void
    {
        $this->first->statement('DROP INDEX legal_signature_requests_pending_idx');
        $this->first->statement("CREATE INDEX legal_signature_requests_pending_idx ON legal_signature_requests (organization_id, expires_at) WHERE status = 'pending'");
        $migration = require dirname(__DIR__, 3).'/database/migrations/2026_07_19_000610_create_legal_document_signature_indexes.php';
        try {
            $migration->up();
            self::fail('A valid index with a wrong descriptor was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertSame('legal_signature_index_descriptor_mismatch:legal_signature_requests_pending_idx', $exception->getMessage());
        }

        $requestId = $this->first->table('legal_signature_requests')->insertGetId($this->requestRow());
        $signatureId = $this->first->table('legal_document_signatures')->insertGetId([
            'organization_id' => 1, 'document_id' => 1, 'document_version_id' => 1, 'signature_request_id' => $requestId,
            'party_id' => null, 'method' => 'paper', 'provider' => null, 'signer_name' => 'Иван',
            'signers' => json_encode([['name' => 'Иван']], JSON_THROW_ON_ERROR), 'signed_content_hash' => str_repeat('a', 64),
            'signature_path' => null, 'signature_content_hash' => null, 'storage_version_id' => null, 'storage_etag' => null,
            'detected_mime_type' => null, 'certificate_metadata' => '{}', 'provider_metadata' => '{}',
            'storage_location' => 'Архив', 'signed_at' => now()->subDay(), 'verified_at' => null,
            'verification_status' => 'registered', 'signature_kind' => 'paper_original', 'container_format' => null,
            'signer_snapshot_hash' => str_repeat('e', 64), 'signer_user_id' => null, 'signer_organization_id' => null,
            'party_role_snapshot' => null, 'certificate_fingerprint' => null, 'certificate_serial' => null,
            'certificate_issuer' => null, 'certificate_valid_from' => null, 'certificate_valid_until' => null,
            'authority_confirmed' => false, 'time_source' => 'operator', 'diagnostic_code' => 'paper_original_registered',
            'signing_session_id' => null, 'client_ip_hash' => null, 'user_agent_hash' => null,
            'revocation_reason' => null, 'registered_by_user_id' => 1,
            'idempotency_key' => 'paper', 'request_hash' => str_repeat('b', 64), 'created_at' => now(), 'updated_at' => now(),
        ]);
        try {
            $this->first->table('legal_document_signatures')->where('id', $signatureId)->update(['storage_location' => 'Подмена']);
            self::fail('Raw signature evidence mutation was accepted.');
        } catch (\Throwable $exception) {
            self::assertStringContainsString('legal_signature_evidence_immutable', $exception->getMessage());
        }
    }

    private function installBaseSchema(): void
    {
        $this->first->unprepared(<<<'SQL'
CREATE TABLE organizations (id bigint PRIMARY KEY);
CREATE TABLE users (id bigint PRIMARY KEY);
CREATE TABLE legal_archive_documents (id bigint PRIMARY KEY, organization_id bigint NOT NULL, UNIQUE (id, organization_id));
CREATE TABLE legal_archive_document_files (id bigint PRIMARY KEY, document_id bigint NOT NULL, organization_id bigint NOT NULL, UNIQUE (id, document_id, organization_id));
CREATE TABLE legal_archive_document_versions (
 id bigint PRIMARY KEY, document_id bigint NOT NULL, document_file_id bigint NOT NULL, organization_id bigint NOT NULL,
 version_number text NOT NULL, version_label text, is_current boolean NOT NULL, status text NOT NULL,
 processing_status text NOT NULL, file_path text NOT NULL, original_filename text NOT NULL, mime_type text,
 size_bytes bigint NOT NULL, content_hash text, metadata_hash text, uploaded_by_user_id bigint, uploaded_at timestamptz,
 metadata jsonb, created_at timestamptz, updated_at timestamptz, UNIQUE (id, document_id, organization_id)
);
CREATE TABLE legal_document_parties (
 id bigint PRIMARY KEY, snapshot_set_id bigint NOT NULL, document_version_id bigint NOT NULL,
 document_id bigint NOT NULL, organization_id bigint NOT NULL
);
CREATE TABLE legal_archive_document_type_profiles (id uuid PRIMARY KEY);
CREATE TABLE legal_archive_file_cleanup_debts (
 id bigserial PRIMARY KEY, organization_id bigint NOT NULL, storage_path text NOT NULL, reason text NOT NULL,
 attempts integer NOT NULL DEFAULT 0, next_attempt_at timestamptz, last_error text, resolved_at timestamptz,
 created_at timestamptz, updated_at timestamptz, UNIQUE (organization_id, storage_path)
);
CREATE TABLE legal_document_access_grants (
 id bigserial PRIMARY KEY, abilities jsonb NOT NULL, subject_kind text NOT NULL
);
ALTER TABLE legal_document_access_grants ADD CONSTRAINT legal_document_access_abilities_check
CHECK (jsonb_typeof(abilities) = 'array' AND jsonb_array_length(abilities) > 0 AND abilities <@ '["view","comment","approve","sign","download","manage"]'::jsonb AND (NOT abilities ? 'manage' OR subject_kind = 'internal_user'));
SQL);
    }

    private function seedAggregate(): void
    {
        $this->first->table('organizations')->insert(['id' => 1]);
        $this->first->table('users')->insert(['id' => 1]);
        $this->first->table('legal_archive_documents')->insert(['id' => 1, 'organization_id' => 1]);
        $this->first->table('legal_archive_document_files')->insert(['id' => 1, 'document_id' => 1, 'organization_id' => 1]);
        $this->first->table('legal_archive_document_versions')->insert([
            'id' => 1, 'document_id' => 1, 'document_file_id' => 1, 'organization_id' => 1,
            'version_number' => '1', 'is_current' => true, 'status' => 'frozen', 'processing_status' => 'ready',
            'file_path' => 'org-1/document.pdf', 'original_filename' => 'document.pdf', 'size_bytes' => 1,
            'content_hash' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function requestRow(): array
    {
        return [
            'organization_id' => 1, 'document_id' => 1, 'document_version_id' => 1, 'party_id' => null,
            'method' => 'paper', 'provider' => null, 'status' => 'pending', 'signed_content_hash' => str_repeat('a', 64),
            'signers' => json_encode([['name' => 'Иван']], JSON_THROW_ON_ERROR), 'signer_snapshot_hash' => str_repeat('e', 64),
            'profile_code' => 'contract.work', 'profile_lock_version' => 0,
            'allowed_signature_kinds' => json_encode(['paper_original'], JSON_THROW_ON_ERROR),
            'required_signature_kinds' => json_encode([], JSON_THROW_ON_ERROR),
            'allowed_signature_formats' => json_encode(['detached_cades'], JSON_THROW_ON_ERROR),
            'requirement_snapshot_hash' => str_repeat('f', 64), 'requirement_group_key' => str_repeat('1', 64),
            'replaces_request_id' => null, 'correlation_id' => str_repeat('c', 64),
            'idempotency_key' => 'same-command', 'request_hash' => str_repeat('d', 64), 'requested_by_user_id' => 1,
            'requested_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ];
    }

    private function connectionConfig(string $dsn): array
    {
        $parts = [];
        foreach (explode(';', preg_replace('/^pgsql:/', '', $dsn) ?? '') as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, null);
            if (is_string($key) && is_string($value)) {
                $parts[$key] = $value;
            }
        }

        return [
            'driver' => 'pgsql', 'host' => $parts['host'] ?? '127.0.0.1', 'port' => $parts['port'] ?? '5432',
            'database' => $parts['dbname'] ?? '', 'username' => (string) getenv('LEGAL_DOCUMENT_PG_TEST_USER'),
            'password' => (string) getenv('LEGAL_DOCUMENT_PG_TEST_PASSWORD'), 'charset' => 'utf8', 'prefix' => '',
        ];
    }
}
