<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use PDOException;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\Support\IsolatedPostgresTestDatabase;

final class RetainedRagProjectionIndexesTest extends TestCase
{
    private const MIGRATION = '2026_10_10_120000_rebuild_retained_rag_projection_indexes';

    private Capsule $capsule;

    private mixed $previousApplication;

    protected function setUp(): void
    {
        parent::setUp();
        $configuration = [
            'driver' => 'pgsql', 'host' => getenv('DB_HOST'), 'port' => getenv('DB_PORT'),
            'database' => getenv('DB_DATABASE'), 'username' => getenv('DB_USERNAME'),
            'password' => getenv('DB_PASSWORD'), 'search_path' => 'public',
        ];
        IsolatedPostgresTestDatabase::assertSafeConfiguration($configuration);
        self::assertMatchesRegularExpression('/^most_phpunit_[a-f0-9]{24}_testing$/D', (string) $configuration['database']);
        $this->capsule = new Capsule;
        $this->capsule->addConnection($configuration);
        $this->previousApplication = Facade::getFacadeApplication();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        DB::swap($this->capsule->getDatabaseManager());
        Log::swap($this->createMock(LoggerInterface::class));
    }

    protected function tearDown(): void
    {
        $this->capsule->getConnection()->disconnect();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousApplication);
        parent::tearDown();
    }

    public function test_owner_rebuilds_through_standard_migrator_and_repeat_is_safe(): void
    {
        $connection = $this->fixture();
        $before = $this->indexes();
        $settings = $this->settings();
        $connection->statement('SET ROLE pg_database_owner');
        $repository = new DatabaseMigrationRepository($this->capsule->getDatabaseManager(), 'rag_reindex_test_migrations');
        $repository->createRepository();
        $migrator = new Migrator($repository, $this->capsule->getDatabaseManager(), new Filesystem);
        $migrator->run([$this->migrationPath()]);
        self::assertContains(self::MIGRATION, $repository->getRan());
        $after = $this->indexes();
        foreach ($before as $name => $index) {
            self::assertSame($index->definition, $after[$name]->definition);
            self::assertNotSame($index->file, $after[$name]->file);
            self::assertSame(1, (int) $after[$name]->healthy);
        }
        self::assertEquals($settings, $this->settings());
        $this->migration()->up();
        self::assertSame(2, (int) $connection->selectOne('SELECT count(*) AS rows FROM public.ai_rag_expected_sources')->rows);
        self::assertEquals($settings, $this->settings());
        $connection->statement('RESET ROLE');
    }

    public function test_corruption_error_is_not_swallowed_and_timeouts_are_restored(): void
    {
        $previous = new PDOException('synthetic index corruption');
        $previous->errorInfo = ['XX002'];
        $failure = new QueryException('default', 'REINDEX INDEX public.ai_rag_expected_coverage_cover_idx', [], $previous);
        $restored = false;
        $connection = $this->createMock(Connection::class);
        $connection->method('selectOne')->willReturnCallback(static function (string $sql, array $bindings = []) use (&$restored): object {
            if (str_contains($sql, 'current_setting')) {
                return (object) ['statement_timeout' => '23s', 'lock_timeout' => '7s'];
            }
            if ($bindings === ['23s', '7s']) {
                $restored = true;
            }

            return (object) ['healthy' => 1, 'can_reindex' => 1];
        });
        $connection->expects(self::once())->method('statement')->willThrowException($failure);
        $manager = $this->createMock(DatabaseManager::class);
        $manager->method('connection')->willReturn($connection);
        DB::swap($manager);
        try {
            $this->migration()->up();
            self::fail('Corruption must refuse deployment.');
        } catch (QueryException $exception) {
            self::assertSame($failure, $exception);
            self::assertSame('XX002', $exception->errorInfo[0]);
            self::assertTrue($restored);
        }
    }

    public function test_non_owner_valid_indexes_defer_without_rebuild_or_definition_changes(): void
    {
        $connection = $this->fixture();
        $before = $this->indexes();
        $settings = $this->settings();
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::exactly(12))->method('warning')
            ->with('rag_projection_index_maintenance_deferred', self::callback(
                static fn (array $context): bool => $context['reason'] === 'non_owner'
                    && str_starts_with($context['index'], 'public.ai_rag_expected_'),
            ));
        Log::swap($logger);
        $connection->statement('SET ROLE pg_read_all_data');
        try {
            self::assertSame(0, (int) $connection->selectOne('SELECT rolsuper::integer AS super FROM pg_roles WHERE rolname = current_user')->super);
            $this->migration()->up();
            $this->migration()->up();
            self::assertEquals($before, $this->indexes());
            self::assertEquals($settings, $this->settings());
        } finally {
            $connection->statement('RESET ROLE');
        }
    }

    #[TestWith([1])]
    #[TestWith([0])]
    #[TestWith([null])]
    public function test_actual_permission_denial_requires_healthy_index_before_deferring(?int $afterHealth): void
    {
        $previous = new PDOException('index maintenance permission denied');
        $previous->errorInfo = ['42501'];
        $failure = new QueryException('default', 'REINDEX INDEX public.ai_rag_expected_coverage_cover_idx', [], $previous);
        $restored = false;
        $indexReads = 0;
        $connection = $this->createMock(Connection::class);
        $connection->method('selectOne')->willReturnCallback(static function (string $sql, array $bindings = []) use (&$restored, &$indexReads, $afterHealth): ?object {
            if (str_contains($sql, 'current_setting')) {
                return (object) ['statement_timeout' => '23s', 'lock_timeout' => '7s'];
            }
            if (str_contains($sql, 'set_config')) {
                if ($bindings === ['23s', '7s']) {
                    $restored = true;
                }

                return (object) [];
            }
            $indexReads++;
            $health = $indexReads % 2 === 1 ? 1 : $afterHealth;

            return $health === null ? null : (object) ['healthy' => $health, 'can_reindex' => 1];
        });
        $connection->expects(self::exactly($afterHealth === 1 ? 6 : 1))->method('statement')->willThrowException($failure);
        $manager = $this->createMock(DatabaseManager::class);
        $manager->method('connection')->willReturn($connection);
        DB::swap($manager);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::exactly($afterHealth === 1 ? 6 : 0))->method('warning')
            ->with('rag_projection_index_maintenance_deferred', self::callback(
                static fn (array $context): bool => $context['reason'] === 'insufficient_privilege'
                    && $context['sqlstate'] === '42501'
                    && str_starts_with($context['index'], 'public.ai_rag_expected_'),
            ));
        Log::swap($logger);
        try {
            $this->migration()->up();
            self::assertSame(1, $afterHealth);
            self::assertSame(12, $indexReads);
        } catch (RuntimeException $exception) {
            self::assertNotSame(1, $afterHealth);
            self::assertSame('rag_projection_index_unhealthy: public.ai_rag_expected_coverage_cover_idx', $exception->getMessage());
            self::assertSame($failure, $exception->getPrevious());
            self::assertSame(2, $indexReads);
        } finally {
            self::assertTrue($restored);
        }
    }

    public function test_non_owner_invalid_index_refuses_and_restores_timeouts(): void
    {
        $connection = $this->fixture();
        $connection->statement('DROP INDEX public.ai_rag_expected_coverage_cover_idx');
        try {
            $connection->statement('CREATE UNIQUE INDEX CONCURRENTLY ai_rag_expected_coverage_cover_idx ON public.ai_rag_expected_sources (source_type)');
            self::fail('Duplicate source types must leave an invalid index.');
        } catch (QueryException $exception) {
            self::assertSame('23505', $exception->errorInfo[0]);
        }
        self::assertSame(0, (int) $this->indexes()['ai_rag_expected_coverage_cover_idx']->healthy);
        $before = $this->indexes();
        $settings = $this->settings();
        $connection->statement('SET ROLE pg_read_all_data');
        try {
            $this->assertHealthRefusal();
            self::assertEquals($before, $this->indexes());
            self::assertEquals($settings, $this->settings());
        } finally {
            $connection->statement('RESET ROLE');
        }
        $this->assertHealthRefusal();
    }

    public function test_wrong_table_index_refuses_and_absent_index_keeps_existing_skip_contract(): void
    {
        $connection = $this->fixture();
        $connection->statement('DROP INDEX public.ai_rag_expected_coverage_cover_idx');
        $this->migration()->up();
        self::assertCount(5, $this->indexes());
        $connection->statement('CREATE TABLE public.wrong_rag_projection (id bigint)');
        $connection->statement('CREATE INDEX ai_rag_expected_coverage_cover_idx ON public.wrong_rag_projection (id)');
        $settings = $this->settings();
        $this->assertHealthRefusal();
        self::assertEquals($settings, $this->settings());
        $connection->statement('DROP INDEX public.ai_rag_expected_coverage_cover_idx');
        $connection->statement('CREATE INDEX ai_rag_expected_coverage_cover_idx ON public.ai_rag_expected_sources (source_type)');
        $connection->statement('SET ROLE pg_read_all_data');
        try {
            $this->assertHealthRefusal();
            self::assertEquals($settings, $this->settings());
        } finally {
            $connection->statement('RESET ROLE');
        }
    }

    public function test_lock_failure_is_not_swallowed_and_timeouts_are_restored(): void
    {
        $connection = $this->fixture();
        $settings = $this->settings();
        $this->capsule->addConnection($connection->getConfig(), 'blocker');
        $blocker = $this->capsule->getConnection('blocker');
        $blocker->beginTransaction();
        $blocker->statement('LOCK TABLE public.ai_rag_expected_sources IN ACCESS EXCLUSIVE MODE');
        try {
            $this->migration()->up();
            self::fail('Locked index maintenance must fail.');
        } catch (QueryException $exception) {
            self::assertSame('55P03', $exception->errorInfo[0]);
            self::assertEquals($settings, $this->settings());
        } finally {
            $blocker->rollBack();
            $blocker->disconnect();
        }
    }

    private function fixture(): Connection
    {
        $connection = $this->capsule->getConnection();
        $connection->statement('SET ROLE pg_database_owner');
        self::assertSame(0, (int) $connection->selectOne('SELECT rolsuper::integer AS super FROM pg_roles WHERE rolname = current_user')->super);
        $connection->statement('DROP TABLE IF EXISTS public.ai_rag_expected_sources CASCADE');
        $connection->statement('CREATE TABLE public.ai_rag_expected_sources (id bigint PRIMARY KEY, organization_id bigint, generation uuid,
            identity_project_id bigint, source_type text, entity_type text, entity_id text, identity_part_key text,
            project_id bigint, checksum text, pending_since timestamp, created_at timestamp, updated_at timestamp)');
        $connection->statement('CREATE UNIQUE INDEX ai_rag_expected_identity_unique ON public.ai_rag_expected_sources
            (organization_id, generation, identity_project_id, source_type, entity_type, entity_id, identity_part_key)');
        $connection->statement('CREATE INDEX ai_rag_expected_scope_idx ON public.ai_rag_expected_sources (organization_id, generation, source_type, project_id)');
        $connection->statement('CREATE INDEX ai_rag_expected_type_entity_idx ON public.ai_rag_expected_sources (organization_id, generation, source_type, entity_type, entity_id)');
        $connection->statement('CREATE INDEX ai_rag_expected_retention_idx ON public.ai_rag_expected_sources (organization_id, created_at, id)');
        $connection->statement('CREATE INDEX ai_rag_expected_coverage_cover_idx ON public.ai_rag_expected_sources
            (organization_id, generation, source_type, entity_type) INCLUDE (id, project_id, identity_project_id, identity_part_key, entity_id, checksum, pending_since, created_at, updated_at)');
        $connection->statement("INSERT INTO public.ai_rag_expected_sources (id, source_type) VALUES (1, 'project'), (2, 'project')");
        $connection->statement('RESET ROLE');
        $connection->selectOne("SELECT set_config('statement_timeout', '23s', false), set_config('lock_timeout', '7s', false)");

        return $connection;
    }

    private function assertHealthRefusal(): void
    {
        try {
            $this->migration()->up();
            self::fail('Unhealthy index must refuse deployment.');
        } catch (RuntimeException $exception) {
            self::assertSame('rag_projection_index_unhealthy: public.ai_rag_expected_coverage_cover_idx', $exception->getMessage());
        }
    }

    private function indexes(): array
    {
        return array_column($this->capsule->getConnection()->select("SELECT c.relname, c.relfilenode AS file, pg_get_indexdef(c.oid) AS definition,
            (i.indisvalid AND i.indisready AND i.indislive)::integer AS healthy FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid
            WHERE i.indrelid = 'public.ai_rag_expected_sources'::regclass ORDER BY c.relname"), null, 'relname');
    }

    private function settings(): ?object
    {
        return $this->capsule->getConnection()->selectOne("SELECT current_setting('statement_timeout') AS statement_timeout, current_setting('lock_timeout') AS lock_timeout");
    }

    private function migrationPath(): string
    {
        return dirname(__DIR__, 3).'/database/migrations/'.self::MIGRATION.'.php';
    }

    private function migration(): mixed
    {
        return require $this->migrationPath();
    }
}
