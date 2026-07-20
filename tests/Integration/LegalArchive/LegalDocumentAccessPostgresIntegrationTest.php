<?php

declare(strict_types=1);

namespace Tests\Integration\LegalArchive;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class LegalDocumentAccessPostgresIntegrationTest extends TestCase
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
            getenv('LEGAL_ARCHIVE_PG_ACCESS_CONTRACT') !== '1'
            || getenv('LEGAL_DOCUMENT_PG_TEST_ALLOW_DDL') !== '1'
            || ! is_string($dsn)
            || $dsn === ''
        ) {
            self::markTestSkipped('Dedicated PostgreSQL access contract database is not enabled.');
        }

        $config = $this->connectionConfig($dsn);
        $this->database = new Capsule;
        $this->database->addConnection($config, 'access_first');
        $this->database->addConnection($config, 'access_second');
        $this->database->setAsGlobal();
        $container = new Container;
        $container->instance('db', $this->database->getDatabaseManager());
        Facade::setFacadeApplication($container);
        $this->database->getDatabaseManager()->setDefaultConnection('access_first');
        $this->first = $this->database->getConnection('access_first');
        $this->second = $this->database->getConnection('access_second');

        $database = (string) $this->first->selectOne('SELECT current_database() AS name')->name;
        if (preg_match('/(?:_test|_testing)$/D', $database) !== 1) {
            self::markTestSkipped('PostgreSQL database name must end with _test or _testing.');
        }
        $serverVersion = (int) $this->first->selectOne("SELECT current_setting('server_version_num') AS version")->version;
        if ($serverVersion < 140000) {
            self::markTestSkipped('PostgreSQL 14 or newer is required.');
        }

        $this->schema = 'legal_access_it_'.bin2hex(random_bytes(6));
        $this->first->statement("CREATE SCHEMA {$this->schema}");
        $this->first->statement("SET search_path TO {$this->schema}");
        $this->second->statement("SET search_path TO {$this->schema}");
        $this->installBaseSchema();
        $this->migration('000500_create_legal_document_parties_access_and_comments')->up();
    }

    protected function tearDown(): void
    {
        if (isset($this->first, $this->schema) && str_starts_with($this->schema, 'legal_access_it_')) {
            $this->first->statement("DROP SCHEMA {$this->schema} CASCADE");
        }
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        parent::tearDown();
    }

    public function test_cross_tenant_counterparty_and_snapshot_version_references_are_rejected(): void
    {
        $this->installAllInvariants();
        [$documentId, $versionId] = $this->dossier(1);
        [, $otherVersionId] = $this->dossier(1);
        $snapshotSetId = $this->snapshotSet($documentId, $versionId);
        $counterpartyId = (int) $this->first->table('counterparties')->insertGetId([
            'organization_id' => 2,
            'name' => 'Чужой контрагент',
        ]);

        $this->assertForeignKeyViolation(fn () => $this->insertParty(
            $documentId,
            $versionId,
            $snapshotSetId,
            $counterpartyId,
        ));
        $this->assertForeignKeyViolation(fn () => $this->insertParty(
            $documentId,
            $otherVersionId,
            $snapshotSetId,
            null,
        ));
    }

    public function test_party_and_snapshot_set_history_is_immutable(): void
    {
        $this->installAllInvariants();
        [$documentId, $versionId] = $this->dossier(1);
        $snapshotSetId = $this->snapshotSet($documentId, $versionId);
        $partyId = $this->insertParty($documentId, $versionId, $snapshotSetId, null);

        $this->assertImmutableViolation(fn () => $this->first->table('legal_document_party_snapshot_sets')
            ->where('id', $snapshotSetId)->update(['captured_at' => now()->addSecond()]));
        $this->assertImmutableViolation(fn () => $this->first->table('legal_document_parties')
            ->where('id', $partyId)->update(['legal_name' => 'Перезаписанная сторона']));
    }

    public function test_descriptor_drift_fails_closed(): void
    {
        $this->migration('000510_create_legal_document_access_indexes')->up();
        $this->first->statement('DROP INDEX counterparties_ownership_unique');
        $this->first->statement('CREATE INDEX counterparties_ownership_unique ON counterparties (organization_id, id)');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('legal_document_access_index_descriptor_mismatch:counterparties_ownership_unique');
        $this->migration('000510_create_legal_document_access_indexes')->up();
    }

    #[DataProvider('indexDriftProvider')]
    public function test_index_descriptor_drift_variants_fail_closed(
        string $index,
        string $replacement,
        int $minimumVersion = 140000,
    ): void {
        $version = (int) $this->first->selectOne("SELECT current_setting('server_version_num') AS version")->version;
        if ($version < $minimumVersion) {
            self::markTestSkipped('This PostgreSQL version does not support the tested descriptor feature.');
        }
        $this->migration('000510_create_legal_document_access_indexes')->up();
        $this->first->statement("DROP INDEX {$index}");
        $this->first->statement($replacement);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("legal_document_access_index_descriptor_mismatch:{$index}");
        $this->migration('000510_create_legal_document_access_indexes')->up();
    }

    public static function indexDriftProvider(): array
    {
        return [
            'desc' => [
                'legal_document_access_lookup_idx',
                'CREATE INDEX legal_document_access_lookup_idx ON legal_document_access_grants '
                .'(subject_organization_id DESC, subject_user_id, document_id, expires_at) WHERE revoked_at IS NULL',
            ],
            'include' => [
                'legal_document_access_lookup_idx',
                'CREATE INDEX legal_document_access_lookup_idx ON legal_document_access_grants '
                .'(subject_organization_id, subject_user_id, document_id, expires_at) INCLUDE (id) WHERE revoked_at IS NULL',
            ],
            'opclass' => [
                'legal_documents_source_identity_unique',
                'CREATE UNIQUE INDEX legal_documents_source_identity_unique ON legal_archive_documents '
                .'(organization_id, source_type text_pattern_ops, source_id) WHERE source_type IS NOT NULL AND source_id IS NOT NULL',
            ],
            'predicate' => [
                'legal_document_access_lookup_idx',
                'CREATE INDEX legal_document_access_lookup_idx ON legal_document_access_grants '
                .'(subject_organization_id, subject_user_id, document_id, expires_at) WHERE revoked_at IS NOT NULL',
            ],
            'nulls-not-distinct' => [
                'legal_documents_source_identity_unique',
                'CREATE UNIQUE INDEX legal_documents_source_identity_unique ON legal_archive_documents '
                .'(organization_id, source_type, source_id) NULLS NOT DISTINCT '
                .'WHERE source_type IS NOT NULL AND source_id IS NOT NULL',
                150000,
            ],
        ];
    }

    public function test_counterparty_fk_descriptor_drift_fails_closed(): void
    {
        $this->migration('000510_create_legal_document_access_indexes')->up();
        $this->first->statement(
            'ALTER TABLE legal_document_parties ADD CONSTRAINT legal_document_parties_counterparty_fk '
            .'FOREIGN KEY (counterparty_id) REFERENCES counterparties (id) ON DELETE RESTRICT NOT VALID',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'legal_document_access_constraint_descriptor_mismatch:legal_document_parties_counterparty_fk',
        );
        $this->migration('000520_add_legal_document_access_constraints')->up();
    }

    public function test_invalid_concurrent_index_is_recovered(): void
    {
        [$documentId] = $this->dossier(1);
        $grant = [
            'organization_id' => 1,
            'document_id' => $documentId,
            'subject_kind' => 'external_org',
            'subject_organization_id' => 2,
            'subject_user_id' => null,
            'subject_role_slug' => null,
            'abilities' => '["view"]',
            'granted_by_user_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        $this->first->table('legal_document_access_grants')->insert([$grant, $grant]);
        try {
            $this->migration('000510_create_legal_document_access_indexes')->up();
            self::fail('Duplicate grants must interrupt concurrent unique-index creation.');
        } catch (Throwable) {
            $state = $this->indexState('legal_document_access_active_subject_unique');
            self::assertNotNull($state);
            self::assertFalse((bool) $state->indisvalid);
        }
        $duplicateId = (int) $this->first->table('legal_document_access_grants')->max('id');
        $this->first->table('legal_document_access_grants')->where('id', $duplicateId)->delete();

        $this->migration('000510_create_legal_document_access_indexes')->up();
        $state = $this->indexState('legal_document_access_active_subject_unique');
        self::assertNotNull($state);
        self::assertTrue((bool) $state->indisvalid);
        self::assertTrue((bool) $state->indisready);
    }

    public function test_parallel_active_grants_preserve_unique_subject_invariant(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('pcntl_waitpid')) {
            self::markTestSkipped('pcntl is required for process-level PostgreSQL race tests.');
        }
        $this->installAllInvariants();
        [$documentId] = $this->dossier(1);
        $gate = 'legal-access-race:'.bin2hex(random_bytes(5));
        $this->first->select('SELECT pg_advisory_lock(hashtextextended(?, 0))', [$gate]);
        $children = [];
        for ($worker = 0; $worker < 2; $worker++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                $manager = $this->database->getDatabaseManager();
                $manager->disconnect('access_first');
                $connection = $manager->connection('access_first');
                $connection->statement("SET search_path TO {$this->schema}");
                $connection->select('SELECT pg_advisory_lock_shared(hashtextextended(?, 0))', [$gate]);
                try {
                    $connection->table('legal_document_access_grants')->insert([
                        'organization_id' => 1, 'document_id' => $documentId,
                        'subject_kind' => 'external_org', 'subject_organization_id' => 2,
                        'subject_user_id' => null, 'subject_role_slug' => null,
                        'abilities' => '["view"]', 'granted_by_user_id' => 1,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $exitCode = 0;
                } catch (Throwable) {
                    $exitCode = 2;
                }
                $connection->select('SELECT pg_advisory_unlock_shared(hashtextextended(?, 0))', [$gate]);
                exit($exitCode);
            }
            if ($pid === -1) {
                throw new RuntimeException('legal_document_access_race_fork_failed');
            }
            $children[] = $pid;
        }
        $this->first->select('SELECT pg_advisory_unlock(hashtextextended(?, 0))', [$gate]);
        $exitCodes = [];
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $exitCodes[] = pcntl_wexitstatus($status);
        }

        sort($exitCodes);
        self::assertSame([0, 2], $exitCodes);
        self::assertSame(1, $this->first->table('legal_document_access_grants')->count());
    }

    public function test_parallel_source_commands_preserve_actor_tenant_idempotency_namespace(): void
    {
        $this->migration('000510_create_legal_document_access_indexes')->up();

        $codes = $this->runParallel(static function (ConnectionInterface $connection, int $worker): void {
            $connection->table('legal_archive_documents')->insert([
                'organization_id' => 1,
                'created_by_user_id' => 1,
                'source_type' => 'contract',
                'source_id' => 100 + $worker,
                'source_idempotency_key' => 'source-command-race',
            ]);
        });

        self::assertSame([0, 2], $codes);
        self::assertSame(1, $this->first->table('legal_archive_documents')->count());

        $this->first->table('legal_archive_documents')->insert([
            'organization_id' => 1,
            'created_by_user_id' => 2,
            'source_type' => 'contract',
            'source_id' => 200,
            'source_idempotency_key' => 'source-command-race',
        ]);
        $this->first->table('legal_archive_documents')->insert([
            'organization_id' => 2,
            'created_by_user_id' => 1,
            'source_type' => 'contract',
            'source_id' => 200,
            'source_idempotency_key' => 'source-command-race',
        ]);

        self::assertSame(3, $this->first->table('legal_archive_documents')->count());

        $this->first->table('legal_archive_documents')->insert([
            'organization_id' => 2,
            'created_by_user_id' => null,
            'source_type' => 'contract',
            'source_id' => 300,
            'source_idempotency_key' => 'system-source-command',
        ]);
        $this->assertUniqueViolation(fn () => $this->first->table('legal_archive_documents')->insert([
            'organization_id' => 2,
            'created_by_user_id' => null,
            'source_type' => 'contract',
            'source_id' => 301,
            'source_idempotency_key' => 'system-source-command',
        ]));
    }

    public function test_parallel_comment_create_and_resolve_preserve_idempotency_and_transition_invariants(): void
    {
        $this->installAllInvariants();
        [$documentId, $versionId] = $this->dossier(1);
        $payload = [
            'organization_id' => 1, 'document_id' => $documentId, 'document_version_id' => $versionId,
            'author_user_id' => 1, 'body' => 'Параллельное замечание', 'visibility' => 'internal',
            'is_blocking' => false, 'status' => 'open', 'idempotency_key' => 'parallel-create',
            'request_hash' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now(),
        ];
        $createCodes = $this->runParallel(static function (ConnectionInterface $connection) use ($payload): void {
            $connection->table('legal_document_comments')->insert($payload);
        });
        self::assertSame([0, 2], $createCodes);
        $commentId = (int) $this->first->table('legal_document_comments')->value('id');

        $resolveCodes = $this->runParallel(static function (ConnectionInterface $connection) use ($commentId): void {
            $updated = $connection->table('legal_document_comments')
                ->where('id', $commentId)
                ->where('status', 'open')
                ->update([
                    'status' => 'resolved', 'resolved_by_user_id' => 1, 'resolved_at' => now(),
                    'resolution_idempotency_key' => 'parallel-resolve',
                    'resolution_request_hash' => str_repeat('b', 64), 'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('legal_document_comment_already_resolved');
            }
        });
        self::assertSame([0, 2], $resolveCodes);
        self::assertSame('resolved', $this->first->table('legal_document_comments')->where('id', $commentId)->value('status'));
    }

    public function test_parallel_expired_regrant_keeps_one_active_grant_and_history(): void
    {
        $this->installAllInvariants();
        [$documentId] = $this->dossier(1);
        $this->first->table('legal_document_access_grants')->insert([
            'organization_id' => 1, 'document_id' => $documentId, 'subject_kind' => 'external_org',
            'subject_organization_id' => 2, 'subject_user_id' => null, 'subject_role_slug' => null,
            'abilities' => '["view"]', 'granted_by_user_id' => 1,
            'expires_at' => now()->subHour(), 'created_at' => now()->subHours(2), 'updated_at' => now()->subHours(2),
        ]);

        $codes = $this->runParallel(static function (ConnectionInterface $connection) use ($documentId): void {
            $connection->transaction(static function () use ($connection, $documentId): void {
                $connection->table('legal_archive_documents')->where('id', $documentId)->lockForUpdate()->first();
                $active = $connection->table('legal_document_access_grants')
                    ->where('document_id', $documentId)->whereNull('revoked_at')->lockForUpdate()->first();
                if ($active !== null && $active->expires_at !== null && strtotime((string) $active->expires_at) <= time()) {
                    $connection->table('legal_document_access_grants')->where('id', $active->id)->update([
                        'revoked_at' => now(), 'revoked_by_user_id' => 1,
                        'revocation_reason' => 'Срок доступа истёк', 'updated_at' => now(),
                    ]);
                    $active = null;
                }
                if ($active === null && ! $connection->table('legal_document_access_grants')
                    ->where('document_id', $documentId)->whereNull('revoked_at')->exists()) {
                    $connection->table('legal_document_access_grants')->insert([
                        'organization_id' => 1, 'document_id' => $documentId, 'subject_kind' => 'external_org',
                        'subject_organization_id' => 2, 'subject_user_id' => null, 'subject_role_slug' => null,
                        'abilities' => '["view"]', 'granted_by_user_id' => 1,
                        'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            });
        });

        self::assertSame([0, 0], $codes);
        self::assertSame(1, $this->first->table('legal_document_access_grants')->whereNull('revoked_at')->count());
        self::assertSame(2, $this->first->table('legal_document_access_grants')->count());
    }

    private function installAllInvariants(): void
    {
        foreach ([
            '000510_create_legal_document_access_indexes',
            '000520_add_legal_document_access_constraints',
            '000530_validate_legal_document_access_constraints',
        ] as $migration) {
            $this->migration($migration)->up();
        }
    }

    private function runParallel(callable $operation): array
    {
        if (! function_exists('pcntl_fork') || ! function_exists('pcntl_waitpid')) {
            self::markTestSkipped('pcntl is required for process-level PostgreSQL race tests.');
        }
        $children = [];
        for ($worker = 0; $worker < 2; $worker++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                $manager = $this->database->getDatabaseManager();
                $manager->disconnect('access_first');
                $connection = $manager->connection('access_first');
                $connection->statement("SET search_path TO {$this->schema}");
                try {
                    $operation($connection, $worker);
                    $exitCode = 0;
                } catch (Throwable) {
                    $exitCode = 2;
                }
                exit($exitCode);
            }
            if ($pid === -1) {
                throw new RuntimeException('legal_document_access_race_fork_failed');
            }
            $children[] = $pid;
        }
        $exitCodes = [];
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $exitCodes[] = pcntl_wexitstatus($status);
        }
        sort($exitCodes);

        return $exitCodes;
    }

    private function migration(string $suffix): object
    {
        return require dirname(__DIR__, 3)."/database/migrations/2026_07_19_{$suffix}.php";
    }

    private function installBaseSchema(): void
    {
        $this->first->unprepared(<<<'SQL'
CREATE TABLE organizations (id bigserial PRIMARY KEY);
CREATE TABLE users (id bigserial PRIMARY KEY);
CREATE TABLE projects (id bigserial PRIMARY KEY, organization_id bigint NOT NULL REFERENCES organizations(id));
CREATE TABLE organization_user (user_id bigint NOT NULL, organization_id bigint NOT NULL, UNIQUE (user_id, organization_id));
CREATE TABLE counterparties (id bigserial PRIMARY KEY, organization_id bigint NOT NULL, name text NOT NULL);
CREATE TABLE legal_archive_documents (
    id bigserial PRIMARY KEY, organization_id bigint NOT NULL, created_by_user_id bigint NULL,
    source_type text NULL, source_id bigint NULL, source_idempotency_key text NULL,
    UNIQUE (id, organization_id)
);
CREATE TABLE legal_archive_document_versions (
    id bigserial PRIMARY KEY, document_id bigint NOT NULL, organization_id bigint NOT NULL,
    UNIQUE (id, document_id, organization_id)
);
INSERT INTO organizations (id) VALUES (1), (2);
INSERT INTO users (id) VALUES (1), (2);
INSERT INTO organization_user (user_id, organization_id) VALUES (1, 1), (2, 2);
SQL);
    }

    /** @return array{int, int} */
    private function dossier(int $organizationId): array
    {
        $documentId = (int) $this->first->table('legal_archive_documents')->insertGetId([
            'organization_id' => $organizationId,
        ]);
        $versionId = (int) $this->first->table('legal_archive_document_versions')->insertGetId([
            'document_id' => $documentId,
            'organization_id' => $organizationId,
        ]);

        return [$documentId, $versionId];
    }

    private function snapshotSet(int $documentId, int $versionId): int
    {
        return (int) $this->first->table('legal_document_party_snapshot_sets')->insertGetId([
            'organization_id' => 1, 'document_id' => $documentId, 'document_version_id' => $versionId,
            'captured_at' => now(), 'captured_by_user_id' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function insertParty(
        int $documentId,
        int $versionId,
        int $snapshotSetId,
        ?int $counterpartyId,
    ): int {
        return (int) $this->first->table('legal_document_parties')->insertGetId([
            'organization_id' => 1, 'document_id' => $documentId, 'document_version_id' => $versionId,
            'snapshot_set_id' => $snapshotSetId, 'counterparty_id' => $counterpartyId,
            'party_role' => 'supplier', 'legal_name' => 'Сторона',
            'data_source' => $counterpartyId === null ? 'manual' : 'counterparty',
            'snapshot' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function assertForeignKeyViolation(callable $operation): void
    {
        try {
            $operation();
            self::fail('Cross-tenant or cross-version reference must be rejected.');
        } catch (Throwable $exception) {
            self::assertSame('23503', $this->sqlState($exception));
        }
    }

    private function assertImmutableViolation(callable $operation): void
    {
        try {
            $operation();
            self::fail('Historical snapshot mutation must be rejected.');
        } catch (Throwable $exception) {
            self::assertSame('55000', $this->sqlState($exception));
        }
    }

    private function assertUniqueViolation(callable $operation): void
    {
        try {
            $operation();
            self::fail('Duplicate command namespace must be rejected.');
        } catch (Throwable $exception) {
            self::assertSame('23505', $this->sqlState($exception));
        }
    }

    private function sqlState(Throwable $exception): string
    {
        $current = $exception;
        while ($current->getPrevious() instanceof Throwable) {
            $current = $current->getPrevious();
        }

        return (string) $current->getCode();
    }

    private function indexState(string $name): ?object
    {
        return $this->first->selectOne(
            'SELECT i.indisvalid::integer AS indisvalid, i.indisready::integer AS indisready '
            .'FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid '
            .'JOIN pg_namespace n ON n.oid = c.relnamespace '
            .'WHERE n.nspname = current_schema() AND c.relname = ?',
            [$name],
        );
    }

    /** @return array<string, mixed> */
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
