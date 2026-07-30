<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWorkspacePreferences;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportWorkspacePreferencesStore;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class ReportWorkspacePreferencesPostgresTest extends TestCase
{
    private Capsule $database;

    private ConnectionInterface $first;

    private ConnectionInterface $second;

    private string $schema;

    protected function setUp(): void
    {
        parent::setUp();

        $dsn = getenv('REPORT_WORKSPACE_PG_TEST_DSN');
        if (
            getenv('REPORT_WORKSPACE_PG_CONTRACT') !== '1'
            || getenv('REPORT_WORKSPACE_PG_TEST_ALLOW_DDL') !== '1'
            || ! is_string($dsn)
            || $dsn === ''
        ) {
            self::markTestSkipped('Dedicated PostgreSQL report workspace contract database is not enabled.');
        }

        $this->database = new Capsule;
        $config = $this->connectionConfig($dsn);
        $this->database->addConnection($config, 'workspace_first');
        $this->database->addConnection($config, 'workspace_second');
        $this->database->setAsGlobal();
        $container = new Container;
        $container->instance('db', $this->database->getDatabaseManager());
        Facade::setFacadeApplication($container);
        $this->database->getDatabaseManager()->setDefaultConnection('workspace_first');
        $this->first = $this->database->getConnection('workspace_first');
        $this->second = $this->database->getConnection('workspace_second');

        $database = (string) $this->first->selectOne('SELECT current_database() AS name')->name;
        if (preg_match('/(?:_test|_testing)$/D', $database) !== 1) {
            self::markTestSkipped('PostgreSQL database name must end with _test or _testing.');
        }

        $this->schema = 'report_workspace_it_'.bin2hex(random_bytes(6));
        $this->first->statement("CREATE SCHEMA {$this->schema}");
        $this->first->statement("SET search_path TO {$this->schema}");
        $this->second->statement("SET search_path TO {$this->schema}");
        $this->installBaseSchema();
        $this->migration()->up();
    }

    protected function tearDown(): void
    {
        if (isset($this->first, $this->schema) && str_starts_with($this->schema, 'report_workspace_it_')) {
            $this->first->statement("DROP SCHEMA {$this->schema} CASCADE");
        }
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function test_migration_creates_one_row_per_organization_and_owner(): void
    {
        $constraints = $this->first->select(
            'SELECT conname FROM pg_constraint WHERE connamespace = current_schema()::regnamespace ORDER BY conname',
        );
        $names = array_map(static fn (object $constraint): string => (string) $constraint->conname, $constraints);

        self::assertContains('report_workspace_preferences_owner_unique', $names);
        self::assertContains('report_workspace_preferences_organization_id_foreign', $names);
        self::assertContains('report_workspace_preferences_owner_id_foreign', $names);
        self::assertSame(0, $this->first->table('report_workspace_preferences')->count());
    }

    public function test_first_write_creates_defaults_and_applies_change_atomically(): void
    {
        $result = $this->store()->updateLocked(1, 1, static fn (ReportWorkspacePreferences $current): ReportWorkspacePreferences => new ReportWorkspacePreferences(
            ['report_one'],
            $current->favouriteReportCodes,
            $current->display,
            $current->updatedAt,
        ));
        $row = $this->first->table('report_workspace_preferences')->first();

        self::assertSame(['report_one'], $result->recentReportCodes);
        self::assertSame([], $result->favouriteReportCodes);
        self::assertSame(1, $this->first->table('report_workspace_preferences')->count());
        self::assertSame(['report_one'], json_decode((string) $row->recent_report_codes, true, 512, JSON_THROW_ON_ERROR));
    }

    public function test_workspace_rows_are_isolated_by_tenant_and_owner(): void
    {
        $store = $this->store();
        $store->updateLocked(1, 1, static fn (ReportWorkspacePreferences $current): ReportWorkspacePreferences => new ReportWorkspacePreferences(
            ['report_one'], [], $current->display, $current->updatedAt,
        ));
        $store->updateLocked(1, 2, static fn (ReportWorkspacePreferences $current): ReportWorkspacePreferences => new ReportWorkspacePreferences(
            ['report_two'], [], $current->display, $current->updatedAt,
        ));
        $store->updateLocked(2, 1, static fn (ReportWorkspacePreferences $current): ReportWorkspacePreferences => new ReportWorkspacePreferences(
            ['report_three'], [], $current->display, $current->updatedAt,
        ));

        self::assertSame(['report_one'], $store->get(1, 1)?->recentReportCodes);
        self::assertSame(['report_two'], $store->get(1, 2)?->recentReportCodes);
        self::assertSame(['report_three'], $store->get(2, 1)?->recentReportCodes);
        self::assertSame(3, $this->first->table('report_workspace_preferences')->count());
    }

    public function test_failed_change_rolls_back_the_first_write(): void
    {
        try {
            $this->store()->updateLocked(1, 1, static function (): ReportWorkspacePreferences {
                throw new RuntimeException('workspace_change_failed');
            });
            self::fail('The callback failure must abort the first write transaction.');
        } catch (RuntimeException $exception) {
            self::assertSame('workspace_change_failed', $exception->getMessage());
        }

        self::assertSame(0, $this->first->table('report_workspace_preferences')->count());
    }

    public function test_update_locked_holds_the_exact_owner_row_lock(): void
    {
        $store = $this->store();
        $store->updateLocked(1, 1, static fn (ReportWorkspacePreferences $current): ReportWorkspacePreferences => $current);

        $store->updateLocked(1, 1, function (ReportWorkspacePreferences $current): ReportWorkspacePreferences {
            $this->second->beginTransaction();
            try {
                $this->second->table('report_workspace_preferences')
                    ->where('organization_id', 1)
                    ->where('owner_id', 1)
                    ->lock('FOR UPDATE NOWAIT')
                    ->first();
                self::fail('The second connection must not acquire the owner row lock.');
            } catch (QueryException $exception) {
                self::assertSame('55P03', $this->sqlState($exception));
            } finally {
                $this->second->rollBack();
            }

            return $current;
        });
    }

    public function test_update_locked_does_not_lock_another_owner_row(): void
    {
        $store = $this->store();
        $store->updateLocked(1, 1, static fn (ReportWorkspacePreferences $current): ReportWorkspacePreferences => $current);
        $store->updateLocked(1, 2, static fn (ReportWorkspacePreferences $current): ReportWorkspacePreferences => $current);

        $store->updateLocked(1, 1, function (ReportWorkspacePreferences $current): ReportWorkspacePreferences {
            $this->second->beginTransaction();
            try {
                $row = $this->second->table('report_workspace_preferences')
                    ->where('organization_id', 1)
                    ->where('owner_id', 2)
                    ->lock('FOR UPDATE NOWAIT')
                    ->first();
                self::assertNotNull($row);
            } finally {
                $this->second->rollBack();
            }

            return $current;
        });

        self::assertSame(2, $this->first->table('report_workspace_preferences')->count());
    }

    public function test_two_concurrent_first_writes_leave_a_single_owner_row(): void
    {
        $codes = $this->runParallel(function (ConnectionInterface $connection, int $worker): void {
            $store = new EloquentReportWorkspacePreferencesStore;
            $code = $worker === 0 ? 'report_one' : 'report_two';
            $store->updateLocked(1, 1, static fn (ReportWorkspacePreferences $current): ReportWorkspacePreferences => new ReportWorkspacePreferences(
                array_values([...$current->recentReportCodes, $code]),
                $current->favouriteReportCodes,
                $current->display,
                $current->updatedAt,
            ));
        });

        self::assertSame([0, 0], $codes);
        self::assertSame(1, $this->first->table('report_workspace_preferences')->where('organization_id', 1)->where('owner_id', 1)->count());
        self::assertCount(2, $this->store()->get(1, 1)?->recentReportCodes ?? []);
        self::assertEqualsCanonicalizing(['report_one', 'report_two'], $this->store()->get(1, 1)?->recentReportCodes ?? []);
    }

    public function test_concurrent_first_writes_preserve_valid_default_display_preferences(): void
    {
        $this->runParallel(function (ConnectionInterface $connection, int $worker): void {
            $store = new EloquentReportWorkspacePreferencesStore;
            $store->updateLocked(1, 1, static fn (ReportWorkspacePreferences $current): ReportWorkspacePreferences => new ReportWorkspacePreferences(
                $current->recentReportCodes,
                ["report_{$worker}"],
                $current->display,
                $current->updatedAt,
            ));
        });
        $workspace = $this->store()->get(1, 1);

        self::assertNotNull($workspace);
        self::assertCount(7, $workspace->display->catalogGroupOrder);
        self::assertSame([], $workspace->display->collapsedCatalogGroups);
        self::assertSame('catalog', $workspace->display->landingSection);
    }

    private function store(): EloquentReportWorkspacePreferencesStore
    {
        return new EloquentReportWorkspacePreferencesStore;
    }

    private function runParallel(callable $operation): array
    {
        if (! function_exists('pcntl_fork') || ! function_exists('pcntl_waitpid') || ! function_exists('stream_socket_pair')) {
            self::markTestSkipped('pcntl and stream sockets are required for PostgreSQL first-write race tests.');
        }

        $pairs = [];
        $children = [];
        for ($worker = 0; $worker < 2; $worker++) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            if ($pair === false) {
                throw new RuntimeException('report_workspace_race_socket_failed');
            }
            $pairs[$worker] = $pair;
            $pid = pcntl_fork();
            if ($pid === 0) {
                fclose($pair[0]);
                $manager = $this->database->getDatabaseManager();
                $manager->disconnect('workspace_first');
                $connection = $manager->connection('workspace_first');
                $connection->statement("SET search_path TO {$this->schema}");
                fwrite($pair[1], 'ready');
                fflush($pair[1]);
                fgets($pair[1]);
                try {
                    $operation($connection, $worker);
                    $exitCode = 0;
                } catch (Throwable) {
                    $exitCode = 2;
                }
                fclose($pair[1]);
                exit($exitCode);
            }
            if ($pid === -1) {
                throw new RuntimeException('report_workspace_race_fork_failed');
            }
            fclose($pair[1]);
            $children[] = $pid;
        }

        foreach ($pairs as $pair) {
            self::assertSame('ready', fread($pair[0], 5));
        }
        foreach ($pairs as $pair) {
            fwrite($pair[0], "go\n");
            fflush($pair[0]);
            fclose($pair[0]);
        }

        $codes = [];
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $codes[] = pcntl_wexitstatus($status);
        }
        sort($codes);

        return $codes;
    }

    private function migration(): object
    {
        return require dirname(__DIR__, 3).'/database/migrations/2026_07_26_000005_create_report_workspace_preferences_table.php';
    }

    private function installBaseSchema(): void
    {
        $this->first->unprepared(<<<'SQL'
CREATE TABLE organizations (id bigint PRIMARY KEY);
CREATE TABLE users (id bigint PRIMARY KEY);
INSERT INTO organizations (id) VALUES (1), (2);
INSERT INTO users (id) VALUES (1), (2);
SQL);
    }

    private function sqlState(Throwable $exception): string
    {
        $current = $exception;
        while ($current->getPrevious() instanceof Throwable) {
            $current = $current->getPrevious();
        }

        return (string) $current->getCode();
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
            'driver' => 'pgsql',
            'host' => $parts['host'] ?? '127.0.0.1',
            'port' => $parts['port'] ?? '5432',
            'database' => $parts['dbname'] ?? '',
            'username' => getenv('REPORT_WORKSPACE_PG_TEST_USER') ?: null,
            'password' => getenv('REPORT_WORKSPACE_PG_TEST_PASSWORD') ?: null,
            'charset' => 'utf8',
            'prefix' => '',
            'schema' => 'public',
            'sslmode' => $parts['sslmode'] ?? 'prefer',
        ];
    }
}
