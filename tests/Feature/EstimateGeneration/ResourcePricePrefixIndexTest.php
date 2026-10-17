<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Monitoring\EstimateGenerationPriceLookupIndexRuntime;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\EloquentNormativeContextPinSource;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeContextPinData;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

final class ResourcePricePrefixIndexTest extends TestCase
{
    private Connection $database;
    private PDO $pdo;

    public function refreshDatabase(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('127.0.0.1', getenv('DB_HOST'));
        self::assertMatchesRegularExpression('/^most_phpunit_[a-f0-9]{24}_testing$/D', (string) getenv('DB_DATABASE'));
        $this->database = DB::connection();
        $this->pdo = $this->database->getPdo();
        $this->pdo->exec("SET statement_timeout='10s'; SET lock_timeout='1s'; SET jit=off");
        $collation = $this->pdo->query("SELECT collname FROM pg_catalog.pg_collation WHERE collprovider='c' AND collname IN ('C.utf8','en_US.utf8','en_US.utf-8') ORDER BY collname LIMIT 1")->fetchColumn();
        self::assertContains($collation, ['C.utf8', 'en_US.utf8', 'en_US.utf-8']);
        $this->pdo->exec('CREATE TABLE estimate_dataset_versions (id bigint PRIMARY KEY, source_type text NOT NULL, version_key text NOT NULL)');
        $this->pdo->exec('CREATE TABLE estimate_regional_price_versions (id bigint PRIMARY KEY, version_key text NOT NULL)');
        $this->pdo->exec('CREATE TABLE estimate_generation_unit_conversions (from_unit text, to_unit text, version integer, is_active boolean, factor numeric)');
        $this->pdo->exec('CREATE TABLE estimate_resource_prices (id bigint PRIMARY KEY, dataset_version_id bigint NOT NULL, construction_resource_id bigint, resource_code varchar(100) COLLATE "'.$collation.'" NOT NULL, resource_name text NOT NULL, price_type text NOT NULL, source_price_kind text, unit text, base_price numeric NOT NULL, regional_price_version_id bigint, region_id bigint, price_zone_id bigint, period_id bigint)');
        $this->pdo->exec("INSERT INTO estimate_dataset_versions VALUES (1,'fsbc','fixture-base'),(2,'regional','fixture-regional'),(3,'fsbc','other-base')");
        $this->pdo->exec("INSERT INTO estimate_regional_price_versions VALUES (1,'fixture-regional'),(2,'other-regional')");
        $this->pdo->exec('CREATE INDEX prices_code_regional_fixture ON estimate_resource_prices (resource_code,regional_price_version_id)');
        $this->pdo->exec('CREATE INDEX prices_base_context_fixture ON estimate_resource_prices (dataset_version_id,id) WHERE regional_price_version_id IS NULL AND base_price>0');
        $this->pdo->exec('CREATE INDEX prices_regional_context_fixture ON estimate_resource_prices (regional_price_version_id,region_id,price_zone_id,period_id,id) WHERE base_price>0');
        $this->pdo->exec("INSERT INTO estimate_resource_prices VALUES
            (300001,1,NULL,'08.1.02.22-0001','Base','base','base','kg',10,NULL,NULL,NULL,NULL),
            (300002,2,NULL,'08.1.02.22-0002','Regional','regional','regional','kg',9,1,1,1,1),
            (300003,2,NULL,'08.1.02.22-0003','Wrong region','regional','regional','kg',1,1,2,1,1),
            (300004,3,NULL,'08.1.02.22-0004','Other base dataset','base','base','kg',1,NULL,NULL,NULL,NULL),
            (300005,1,NULL,'08.1.02.22-0005','Nonpositive','base','base','kg',0,NULL,NULL,NULL,NULL),
            (300006,1,NULL,'08.1.02.22-0006','Salary','base','regional_worker_salary','kg',1,NULL,NULL,NULL,NULL),
            (300007,1,NULL,'08.1.02.22-0007','Normalized unit','base','base',' K.G. ',8,NULL,NULL,NULL,NULL),
            (300008,1,NULL,'08.1.02.22-0008','Converted unit','base','base','t',7,NULL,NULL,NULL,NULL),
            (300009,1,NULL,'08X1.02.22-0009','Wildcard character','base','base','kg',6,NULL,NULL,NULL,NULL)");
        $this->pdo->exec("INSERT INTO estimate_generation_unit_conversions VALUES ('kg','t',1,true,0.001)");
        $this->pdo->exec('ANALYZE estimate_resource_prices');
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->database->disableQueryLog();
            $this->pdo->exec('DROP TABLE IF EXISTS estimate_resource_prices,estimate_generation_unit_conversions,estimate_regional_price_versions,estimate_dataset_versions CASCADE');
        }
        parent::tearDown();
    }

    public function test_actual_builder_uses_bounded_prefix_scan_without_changing_context_units_or_wildcards(): void
    {
        $this->pdo->exec("INSERT INTO estimate_resource_prices SELECT n,1,NULL,'99.'||lpad((n/10000)::text,4,'0')||'-'||lpad((n%10000)::text,4,'0'),'Unrelated resource','base','base','kg',100,NULL,NULL,NULL,NULL FROM generate_series(1,200000) n");
        $this->pdo->exec('ANALYZE estimate_resource_prices');
        $before = $this->runBuilder('08.1.02.22');
        self::assertSame([300008, 300007, 300002, 300001], array_column($before['rows'], 'price_id'));
        $wildcardBefore = $this->runBuilder('08_1.02.22');
        self::assertSame([300009, 300008, 300007, 300002, 300001], array_column($wildcardBefore['rows'], 'price_id'));
        $percentBefore = $this->runBuilder('08.1.%');
        $baseline = $this->plan($before);
        $this->migration()->up();
        $this->migration()->up();
        $this->pdo->exec('ANALYZE estimate_resource_prices');
        $after = $this->runBuilder('08.1.02.22');
        $candidate = $this->plan($after);
        self::assertSame($before, $after);
        self::assertSame($wildcardBefore, $this->runBuilder('08_1.02.22'));
        self::assertSame($percentBefore, $this->runBuilder('08.1.%'));
        self::assertGreaterThanOrEqual(200000, $baseline['price_heap_rows_examined']);
        self::assertLessThan(100, $candidate['price_heap_rows_examined']);
        self::assertContains('eg_prices_resource_code_pattern_idx', $candidate['indexes']);
        $path = getenv('MOST_PRICE_PREFIX_EVIDENCE');
        if (is_string($path) && $path !== '') {
            file_put_contents($path, json_encode(['actual_product_builder' => true, 'synthetic_rows' => 200009,
                'baseline' => $baseline, 'candidate' => $candidate, 'strict_result_and_SQL_parity' => true], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
    }

    public function test_migration_down_and_reapply_preserve_existing_lookup_indexes(): void
    {
        $this->migration()->up();
        $first = $this->indexState();
        self::assertTrue($first['indisvalid']);
        self::assertTrue($first['indisready']);
        $this->migration()->up();
        self::assertSame($first, $this->indexState());
        $this->migration()->down();
        $this->migration()->down();
        self::assertNull($this->indexState());
        foreach (['prices_code_regional_fixture', 'prices_base_context_fixture', 'prices_regional_context_fixture'] as $name) {
            self::assertNotFalse($this->pdo->query("SELECT to_regclass('public.".$name."') IS NOT NULL")->fetchColumn());
        }
        $this->migration()->up();
        self::assertTrue($this->indexState()['indisvalid']);
    }

    public function test_addon_install_lifecycle_includes_prefix_index(): void
    {
        $runtime = app(EstimateGenerationPriceLookupIndexRuntime::class);
        $runtime->ensureAll();
        $runtime->ensureAll();
        self::assertTrue($this->indexState()['indisvalid']);
        $runtime->dropAll();
        self::assertNull($this->indexState());
        $runtime->ensureAll();
        self::assertTrue($this->indexState()['indisvalid']);
    }

    public function test_absent_source_is_a_noop(): void
    {
        $this->pdo->exec('DROP TABLE estimate_resource_prices');
        $this->migration()->up();
        $this->migration()->down();
        self::assertNull($this->indexState());
    }

    public function test_managed_transaction_is_rejected_without_ddl(): void
    {
        $this->database->beginTransaction();
        try {
            $this->assertTransactionRejected();
        } finally {
            $this->database->rollBack();
        }
        self::assertNull($this->indexState());
    }

    public function test_raw_transaction_is_rejected_without_ddl(): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->assertTransactionRejected();
        } finally {
            $this->pdo->rollBack();
        }
        self::assertNull($this->indexState());
    }

    public function test_table_prefix_is_rejected_without_ddl(): void
    {
        $this->database->setTablePrefix('fixture_');
        try {
            $this->assertTransactionRejected();
        } finally {
            $this->database->setTablePrefix('');
        }
        self::assertNull($this->indexState());
    }

    public function test_valid_wrong_definition_is_preserved_by_up_and_down(): void
    {
        $this->pdo->exec('CREATE INDEX eg_prices_resource_code_pattern_idx ON estimate_resource_prices (unit)');
        $this->assertCollisionPreserved();
    }

    public function test_invalid_wrong_definition_is_preserved_by_up_and_down(): void
    {
        try {
            $this->pdo->exec('CREATE UNIQUE INDEX CONCURRENTLY eg_prices_resource_code_pattern_idx ON estimate_resource_prices (unit)');
            self::fail('Expected duplicate fixture units to reject the unique index');
        } catch (PDOException $exception) {
            self::assertSame('23505', $exception->getCode());
        }
        self::assertFalse($this->indexState()['indisvalid']);
        $this->assertCollisionPreserved();
    }

    public function test_index_write_cost_is_measured_with_identical_fixture_inserts(): void
    {
        $baseline = $this->writeCost();
        $this->migration()->up();
        $indexed = $this->writeCost();
        self::assertSame(2000, $baseline['rows']);
        self::assertSame($baseline['rows'], $indexed['rows']);
        self::assertGreaterThan(0, $baseline['wal_bytes']);
        self::assertGreaterThan(0, $indexed['wal_bytes']);
        $path = getenv('MOST_PRICE_PREFIX_WRITE_EVIDENCE');
        if (is_string($path) && $path !== '') {
            file_put_contents($path, json_encode(['synthetic_private_runtime' => true, 'baseline' => $baseline,
                'indexed' => $indexed, 'index_bytes' => (int) $this->pdo->query("SELECT pg_relation_size('public.eg_prices_resource_code_pattern_idx')")->fetchColumn()], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
    }

    public function test_matching_invalid_index_is_rebuilt_after_interrupted_concurrent_creation(): void
    {
        $configuration = $this->database->getConfig();
        $locker = new PDO('pgsql:host=127.0.0.1;port='.$configuration['port'].';dbname='.$configuration['database'],
            $configuration['username'], $configuration['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $locker->beginTransaction();
        try {
            $locker->exec('LOCK TABLE estimate_resource_prices IN ROW EXCLUSIVE MODE');
            $this->pdo->exec("SET statement_timeout='200ms'");
            try {
                $this->pdo->exec('CREATE INDEX CONCURRENTLY eg_prices_resource_code_pattern_idx ON public.estimate_resource_prices (resource_code varchar_pattern_ops) WHERE base_price > 0');
                self::fail('Expected the fixture writer to block concurrent index creation');
            } catch (PDOException $exception) {
                self::assertSame('57014', $exception->getCode());
            }
        } finally {
            $locker->rollBack();
            $this->pdo->exec("SET statement_timeout='10s'");
        }
        self::assertFalse($this->indexState()['indisvalid']);
        $this->migration()->up();
        self::assertTrue($this->indexState()['indisvalid']);
        self::assertTrue($this->indexState()['indisready']);
    }

    private function migration(): \Illuminate\Database\Migrations\Migration
    {
        return require base_path('database/migrations/2026_10_17_040000_add_resource_price_prefix_index.php');
    }

    private function indexState(): ?array
    {
        $row = $this->pdo->query("SELECT c.oid,i.indisvalid,i.indisready,pg_get_indexdef(c.oid) AS definition FROM pg_class c JOIN pg_index i ON i.indexrelid=c.oid WHERE c.oid=to_regclass('public.eg_prices_resource_code_pattern_idx')")->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function assertTransactionRejected(): void
    {
        foreach (['up', 'down'] as $method) {
            try {
                $this->migration()->{$method}();
                self::fail('Expected guarded migration to reject this connection state');
            } catch (RuntimeException $exception) {
                self::assertSame('Resource price prefix index requires an unprefixed PostgreSQL connection outside a transaction', $exception->getMessage());
            }
        }
    }

    private function assertCollisionPreserved(): void
    {
        $before = $this->indexState();
        foreach (['up', 'down'] as $method) {
            try {
                $this->migration()->{$method}();
                self::fail('Expected a different index definition to be rejected');
            } catch (RuntimeException $exception) {
                self::assertSame('estimate_generation_price_prefix_index_definition_mismatch', $exception->getMessage());
            }
            self::assertSame($before, $this->indexState());
        }
    }

    private function writeCost(): array
    {
        $this->pdo->exec('DELETE FROM estimate_resource_prices WHERE id>=500000');
        $startLsn = $this->pdo->query('SELECT pg_current_wal_insert_lsn()')->fetchColumn();
        $started = hrtime(true);
        $rows = $this->pdo->exec("INSERT INTO estimate_resource_prices SELECT 500000+n,1,NULL,'88.'||lpad(n::text,8,'0'),'Write fixture','base','base','kg',100,NULL,NULL,NULL,NULL FROM generate_series(1,2000) n");
        $elapsed = (hrtime(true) - $started) / 1_000_000;
        $statement = $this->pdo->prepare('SELECT pg_wal_lsn_diff(pg_current_wal_insert_lsn(),?::pg_lsn)');
        $statement->execute([$startLsn]);

        return ['rows' => $rows, 'elapsed_ms' => $elapsed, 'wal_bytes' => (int) $statement->fetchColumn()];
    }

    private function runBuilder(string $group): array
    {
        $this->database->flushQueryLog();
        $this->database->enableQueryLog();
        $definition = (object) ['id' => 1, 'estimate_norm_id' => 1, 'construction_resource_id' => null,
            'resource_code' => $group, 'resource_name' => 'Abstract fixture', 'resource_type' => 'material', 'unit' => 'kg', 'quantity' => 1];
        $context = new NormativeContextPinData(1, 'fixture-base', '2026-10-17', 1, 1, 1, 1, 'fixture-regional');
        $rows = (new ReflectionMethod(EloquentNormativeContextPinSource::class, 'abstractPriceRowsForDefinition'))
            ->invoke(new EloquentNormativeContextPinSource($this->database), $definition, $context, [1]);
        self::assertInstanceOf(Collection::class, $rows);
        $queries = array_values(array_filter($this->database->getQueryLog(), static fn (array $query): bool => str_contains($query['query'], 'from "estimate_resource_prices" as "prices"')));
        $this->database->disableQueryLog();
        self::assertCount(1, $queries);

        return ['rows' => $rows->map(static fn (object $row): array => (array) $row)->all(), 'SQL' => $queries[0]['query'], 'bindings' => $queries[0]['bindings']];
    }

    private function plan(array $captured): array
    {
        $statement = $this->pdo->prepare('EXPLAIN (ANALYZE,BUFFERS,FORMAT JSON) '.$captured['SQL']);
        $statement->execute($this->database->prepareBindings($captured['bindings']));
        $root = json_decode($statement->fetchColumn(), true, 128, JSON_THROW_ON_ERROR)[0];
        $statement->closeCursor();
        $result = ['execution_ms' => $root['Execution Time'], 'planning_ms' => $root['Planning Time'], 'price_heap_rows_examined' => 0, 'indexes' => []];
        $walk = static function (array $node) use (&$walk, &$result): void {
            if (($node['Relation Name'] ?? null) === 'estimate_resource_prices'
                && in_array($node['Node Type'], ['Seq Scan', 'Index Scan', 'Index Only Scan', 'Bitmap Heap Scan'], true)) {
                $result['price_heap_rows_examined'] += (($node['Actual Rows'] ?? 0) + ($node['Rows Removed by Filter'] ?? 0) + ($node['Rows Removed by Index Recheck'] ?? 0)) * ($node['Actual Loops'] ?? 0);
            }
            if (isset($node['Index Name'])) { $result['indexes'][] = $node['Index Name']; }
            foreach ($node['Plans'] ?? [] as $child) { $walk($child); }
        };
        $walk($root['Plan']);

        return $result;
    }
}
