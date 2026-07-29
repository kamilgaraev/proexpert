<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting\Persistence;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Reporting\PostgresProcessRaceHarness;
use Tests\TestCase;
use Throwable;

#[Group('postgresql')]
final class ReportTypedResourceScopeCutoverMigrationTest extends TestCase
{
    private string $connectionName;

    private string $schema;

    private string $originalDefaultConnection;

    protected function setUp(): void
    {
        parent::setUp();

        self::assertSame('pgsql', DB::connection()->getDriverName());
        $this->originalDefaultConnection = (string) config('database.default');
        $this->schema = 'report_scope_cutover_'.bin2hex(random_bytes(6));
        $this->connectionName = $this->schema.'_connection';

        DB::statement(sprintf('CREATE SCHEMA %s', $this->quoteIdentifier($this->schema)));
        $configuration = config("database.connections.{$this->originalDefaultConnection}");
        self::assertIsArray($configuration);
        $configuration['search_path'] = $this->schema;
        $configuration['schema'] = $this->schema;
        config([
            "database.connections.{$this->connectionName}" => $configuration,
            'database.default' => $this->connectionName,
        ]);
        DB::purge($this->connectionName);
    }

    protected function tearDown(): void
    {
        if (isset($this->originalDefaultConnection)) {
            DB::purge($this->connectionName);
            config(['database.default' => $this->originalDefaultConnection]);
            DB::connection($this->originalDefaultConnection)->statement(sprintf(
                'DROP SCHEMA IF EXISTS %s CASCADE',
                $this->quoteIdentifier($this->schema),
            ));
            config(["database.connections.{$this->connectionName}" => null]);
        }

        parent::tearDown();
    }

    public function test_up_and_down_execute_against_an_isolated_postgresql_table(): void
    {
        $this->createLegacyTable();
        $migration = $this->migration();

        $migration->up();

        self::assertSame(['id', 'scope_resources'], $this->columns());
        self::assertFalse($this->hasDurableDualScopeColumns());
        DB::table('report_runs')->insert(['scope_resources' => '[]']);

        $migration->down();

        self::assertSame(['id', 'scope_resource_ids'], $this->columns());
        self::assertFalse($this->hasDurableDualScopeColumns());
        self::assertSame('[]', DB::table('report_runs')->value('scope_resource_ids'));
    }

    #[DataProvider('migrationDirections')]
    public function test_non_postgresql_driver_is_rejected_before_transaction_lock_or_ddl(string $direction): void
    {
        $databaseManager = app('db');
        $connection = \Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('getDriverName')->once()->andReturn('mysql');
        DB::shouldReceive('connection')->once()->andReturn($connection);
        DB::shouldReceive('transaction')->never();
        DB::shouldReceive('statement')->never();

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('report_scope_resources_cutover_requires_postgresql');
            match ($direction) {
                'up' => $this->migration()->up(),
                'down' => $this->migration()->down(),
            };
        } finally {
            DB::swap($databaseManager);
        }
    }

    public static function migrationDirections(): iterable
    {
        yield 'up' => ['up'];
        yield 'down' => ['down'];
    }

    public function test_up_aborts_on_non_empty_legacy_scope_without_changing_schema_or_data(): void
    {
        $this->createLegacyTable();
        DB::table('report_runs')->insert(['scope_resource_ids' => '["project:17"]']);
        $before = $this->schemaSnapshot();

        try {
            $this->migration()->up();
            self::fail('Expected non-empty legacy scope to abort the cutover.');
        } catch (\RuntimeException $exception) {
            self::assertSame('report_scope_resources_cutover_requires_empty_source_scope', $exception->getMessage());
        }

        self::assertSame($before, $this->schemaSnapshot());
        self::assertSame('["project:17"]', DB::table('report_runs')->value('scope_resource_ids'));
        self::assertFalse($this->hasDurableDualScopeColumns());
    }

    #[DataProvider('malformedLegacyScopes')]
    public function test_up_aborts_on_malformed_legacy_scope_where_permissive_old_schema_allows_it(
        ?string $json,
    ): void {
        $this->createPermissiveLegacyTable();
        DB::statement(
            'INSERT INTO report_runs (scope_resource_ids) VALUES (CAST(? AS jsonb))',
            [$json],
        );
        $before = $this->schemaSnapshot();

        try {
            $this->migration()->up();
            self::fail('Expected malformed legacy scope to abort the cutover.');
        } catch (\RuntimeException $exception) {
            self::assertSame('report_scope_resources_cutover_requires_empty_source_scope', $exception->getMessage());
        }

        self::assertSame($before, $this->schemaSnapshot());
        self::assertFalse($this->hasDurableDualScopeColumns());
    }

    public static function malformedLegacyScopes(): iterable
    {
        yield 'null' => [null];
        yield 'object' => ['{"project":17}'];
        yield 'string' => ['"project:17"'];
        yield 'number' => ['17'];
    }

    public function test_down_aborts_on_non_empty_typed_scope_without_changing_schema_or_data(): void
    {
        $this->createTypedTable();
        DB::table('report_runs')->insert([
            'scope_resources' => '[{"kind":"project","id":17}]',
        ]);
        $before = $this->schemaSnapshot();

        try {
            $this->migration()->down();
            self::fail('Expected non-empty typed scope to abort the rollback.');
        } catch (\RuntimeException $exception) {
            self::assertSame('report_scope_resources_rollback_requires_empty_typed_scope', $exception->getMessage());
        }

        self::assertSame($before, $this->schemaSnapshot());
        self::assertSame(
            '[{"id":17,"kind":"project"}]',
            DB::table('report_runs')->value('scope_resources'),
        );
        self::assertFalse($this->hasDurableDualScopeColumns());
    }

    public function test_up_rolls_back_every_ddl_step_when_a_mid_cutover_statement_fails(): void
    {
        $this->createLegacyTable();
        DB::statement(
            'ALTER TABLE report_runs ADD CONSTRAINT report_runs_scope_resources_array CHECK (true)',
        );
        $before = $this->schemaSnapshot();

        try {
            $this->migration()->up();
            self::fail('Expected the injected constraint collision to fail the cutover.');
        } catch (Throwable) {
            self::assertSame($before, $this->schemaSnapshot());
            self::assertFalse($this->hasDurableDualScopeColumns());
        }
    }

    public function test_down_rolls_back_every_ddl_step_when_a_mid_rollback_statement_fails(): void
    {
        $this->createTypedTable();
        DB::statement(
            'ALTER TABLE report_runs ADD CONSTRAINT report_runs_scope_resource_ids_array CHECK (true)',
        );
        $before = $this->schemaSnapshot();

        try {
            $this->migration()->down();
            self::fail('Expected the injected constraint collision to fail the rollback.');
        } catch (Throwable) {
            self::assertSame($before, $this->schemaSnapshot());
            self::assertFalse($this->hasDurableDualScopeColumns());
        }
    }

    public function test_access_exclusive_cutover_fences_a_concurrent_legacy_writer_and_observer_sees_old_then_final_schema(): void
    {
        $this->createLegacyTable();
        DB::table('report_runs')->insert(['scope_resource_ids' => '[]']);
        $observer = $this->independentConnection('observer');
        self::assertSame(['id', 'scope_resource_ids'], $this->columns($observer));

        $suffix = bin2hex(random_bytes(6));
        $harness = new PostgresProcessRaceHarness(
            sys_get_temp_dir().DIRECTORY_SEPARATOR."report-scope-cutover-race-{$suffix}",
        );
        $children = [];
        $transactionOpen = false;

        try {
            DB::beginTransaction();
            $transactionOpen = true;
            $this->migration()->up();
            self::assertSame(['id', 'scope_resources'], $this->columns());
            self::assertFalse($this->hasDurableDualScopeColumns());

            $children[] = $harness->spawn(1, static function (): array {
                try {
                    DB::table('report_runs')->update(['scope_resource_ids' => '[]']);

                    return ['legacy_write_succeeded' => true];
                } catch (Throwable $exception) {
                    return [
                        'legacy_write_succeeded' => false,
                        'sql_state' => (string) $exception->getCode(),
                    ];
                }
            });
            $harness->release(1);
            $workerPid = $harness->waitForWorkerBackendPid(1);
            $harness->waitForPostgresWait($observer, $workerPid, 'relation');

            DB::commit();
            $transactionOpen = false;
            $harness->waitForChildren($children);
            $children = [];

            $result = $harness->result(1);
            self::assertFalse($result['legacy_write_succeeded']);
            self::assertSame('42703', $result['sql_state']);
            self::assertSame(['id', 'scope_resources'], $this->columns($observer));
            self::assertFalse($this->hasDurableDualScopeColumns($observer));
        } finally {
            if ($transactionOpen) {
                DB::rollBack();
            }
            $harness->terminateAndReap($children);
            DB::purge($this->schema.'_observer');
            $harness->cleanup();
        }
    }

    public function test_concurrent_legacy_writer_committed_before_cutover_is_revalidated_and_preserves_old_schema(): void
    {
        $this->createLegacyTable();
        DB::table('report_runs')->insert(['scope_resource_ids' => '[]']);
        $observer = $this->independentConnection('writer_first_observer');
        $suffix = bin2hex(random_bytes(6));
        $harness = new PostgresProcessRaceHarness(
            sys_get_temp_dir().DIRECTORY_SEPARATOR."report-scope-writer-first-{$suffix}",
        );
        $children = [];
        $transactionOpen = false;

        try {
            DB::beginTransaction();
            $transactionOpen = true;
            DB::table('report_runs')->update(['scope_resource_ids' => '["project:99"]']);

            $children[] = $harness->spawn(2, function (): array {
                try {
                    $this->migration()->up();

                    return ['cutover_succeeded' => true, 'error' => ''];
                } catch (Throwable $exception) {
                    return ['cutover_succeeded' => false, 'error' => $exception->getMessage()];
                }
            });
            $harness->release(2);
            $workerPid = $harness->waitForWorkerBackendPid(2);
            $harness->waitForPostgresWait($observer, $workerPid, 'relation');

            DB::commit();
            $transactionOpen = false;
            $harness->waitForChildren($children);
            $children = [];

            self::assertSame([
                'cutover_succeeded' => false,
                'error' => 'report_scope_resources_cutover_requires_empty_source_scope',
            ], $harness->result(2));
            self::assertSame(['id', 'scope_resource_ids'], $this->columns($observer));
            self::assertSame('["project:99"]', $observer->table('report_runs')->value('scope_resource_ids'));
            self::assertFalse($this->hasDurableDualScopeColumns($observer));
        } finally {
            if ($transactionOpen) {
                DB::rollBack();
            }
            $harness->terminateAndReap($children);
            DB::purge($this->schema.'_writer_first_observer');
            $harness->cleanup();
        }
    }

    public function test_access_exclusive_rollback_fences_a_concurrent_typed_writer_and_observer_sees_typed_then_final_legacy_schema(): void
    {
        $this->createTypedTable();
        DB::table('report_runs')->insert(['scope_resources' => '[]']);
        $observer = $this->independentConnection('down_observer');
        self::assertSame(['id', 'scope_resources'], $this->columns($observer));

        $suffix = bin2hex(random_bytes(6));
        $harness = new PostgresProcessRaceHarness(
            sys_get_temp_dir().DIRECTORY_SEPARATOR."report-scope-rollback-race-{$suffix}",
        );
        $children = [];
        $transactionOpen = false;

        try {
            DB::beginTransaction();
            $transactionOpen = true;
            $this->migration()->down();
            self::assertSame(['id', 'scope_resource_ids'], $this->columns());
            self::assertFalse($this->hasDurableDualScopeColumns());

            $children[] = $harness->spawn(3, static function (): array {
                try {
                    DB::table('report_runs')->update(['scope_resources' => '[]']);

                    return ['typed_write_succeeded' => true];
                } catch (Throwable $exception) {
                    return [
                        'typed_write_succeeded' => false,
                        'sql_state' => (string) $exception->getCode(),
                    ];
                }
            });
            $harness->release(3);
            $workerPid = $harness->waitForWorkerBackendPid(3);
            $harness->waitForPostgresWait($observer, $workerPid, 'relation');

            DB::commit();
            $transactionOpen = false;
            $harness->waitForChildren($children);
            $children = [];

            $result = $harness->result(3);
            self::assertFalse($result['typed_write_succeeded']);
            self::assertSame('42703', $result['sql_state']);
            self::assertSame(['id', 'scope_resource_ids'], $this->columns($observer));
            self::assertFalse($this->hasDurableDualScopeColumns($observer));
        } finally {
            if ($transactionOpen) {
                DB::rollBack();
            }
            $harness->terminateAndReap($children);
            DB::purge($this->schema.'_down_observer');
            $harness->cleanup();
        }
    }

    public function test_concurrent_typed_writer_committed_before_rollback_is_revalidated_and_preserves_typed_schema(): void
    {
        $this->createTypedTable();
        DB::table('report_runs')->insert(['scope_resources' => '[]']);
        $observer = $this->independentConnection('down_writer_first_observer');
        $suffix = bin2hex(random_bytes(6));
        $harness = new PostgresProcessRaceHarness(
            sys_get_temp_dir().DIRECTORY_SEPARATOR."report-scope-down-writer-first-{$suffix}",
        );
        $children = [];
        $transactionOpen = false;

        try {
            DB::beginTransaction();
            $transactionOpen = true;
            DB::table('report_runs')->update([
                'scope_resources' => '[{"kind":"project","id":99,"project_id":99}]',
            ]);

            $children[] = $harness->spawn(4, function (): array {
                try {
                    $this->migration()->down();

                    return ['rollback_succeeded' => true, 'error' => ''];
                } catch (Throwable $exception) {
                    return ['rollback_succeeded' => false, 'error' => $exception->getMessage()];
                }
            });
            $harness->release(4);
            $workerPid = $harness->waitForWorkerBackendPid(4);
            $harness->waitForPostgresWait($observer, $workerPid, 'relation');

            DB::commit();
            $transactionOpen = false;
            $harness->waitForChildren($children);
            $children = [];

            self::assertSame([
                'rollback_succeeded' => false,
                'error' => 'report_scope_resources_rollback_requires_empty_typed_scope',
            ], $harness->result(4));
            self::assertSame(['id', 'scope_resources'], $this->columns($observer));
            self::assertSame(
                '[{"id":99,"kind":"project","project_id":99}]',
                $observer->table('report_runs')->value('scope_resources'),
            );
            self::assertFalse($this->hasDurableDualScopeColumns($observer));
        } finally {
            if ($transactionOpen) {
                DB::rollBack();
            }
            $harness->terminateAndReap($children);
            DB::purge($this->schema.'_down_writer_first_observer');
            $harness->cleanup();
        }
    }

    private function migration(): Migration
    {
        $migration = require dirname(__DIR__, 4)
            .'/database/migrations/2026_07_29_000004_cut_over_report_scope_resources.php';
        self::assertInstanceOf(Migration::class, $migration);

        return $migration;
    }

    private function createLegacyTable(): void
    {
        DB::statement(
            "CREATE TABLE report_runs (
                id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                scope_resource_ids jsonb NOT NULL DEFAULT '[]'::jsonb,
                CONSTRAINT report_runs_scope_resource_ids_array
                    CHECK (jsonb_typeof(scope_resource_ids) = 'array')
            )",
        );
    }

    private function createPermissiveLegacyTable(): void
    {
        DB::statement(
            'CREATE TABLE report_runs (
                id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                scope_resource_ids jsonb NULL
            )',
        );
    }

    private function createTypedTable(): void
    {
        DB::statement(
            "CREATE TABLE report_runs (
                id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                scope_resources jsonb NOT NULL DEFAULT '[]'::jsonb,
                CONSTRAINT report_runs_scope_resources_array
                    CHECK (jsonb_typeof(scope_resources) = 'array')
            )",
        );
    }

    private function independentConnection(string $suffix): ConnectionInterface
    {
        $name = $this->schema.'_'.$suffix;
        config(["database.connections.{$name}" => config("database.connections.{$this->connectionName}")]);
        DB::purge($name);

        return DB::connection($name);
    }

    private function columns(?ConnectionInterface $connection = null): array
    {
        $connection ??= DB::connection();

        return array_map(
            static fn (object $row): string => (string) $row->column_name,
            $connection->select(
                'SELECT column_name
                 FROM information_schema.columns
                 WHERE table_schema = ? AND table_name = ?
                 ORDER BY ordinal_position',
                [$this->schema, 'report_runs'],
            ),
        );
    }

    private function schemaSnapshot(): array
    {
        return [
            'columns' => array_map(
                static fn (object $row): array => (array) $row,
                DB::select(
                    'SELECT column_name, data_type, is_nullable, column_default
                     FROM information_schema.columns
                     WHERE table_schema = ? AND table_name = ?
                     ORDER BY ordinal_position',
                    [$this->schema, 'report_runs'],
                ),
            ),
            'constraints' => array_map(
                static fn (object $row): array => (array) $row,
                DB::select(
                    'SELECT constraint_name, constraint_type
                     FROM information_schema.table_constraints
                     WHERE table_schema = ? AND table_name = ?
                     ORDER BY constraint_name',
                    [$this->schema, 'report_runs'],
                ),
            ),
        ];
    }

    private function hasDurableDualScopeColumns(?ConnectionInterface $connection = null): bool
    {
        $columns = $this->columns($connection);

        return in_array('scope_resource_ids', $columns, true)
            && in_array('scope_resources', $columns, true);
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
