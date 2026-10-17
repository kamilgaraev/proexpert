<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use PDO;
use Tests\TestCase;
use Illuminate\Support\Facades\DB;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Import\EstimateImportStatisticsService;

final class ExactEstimateResourcePriceCountTest extends TestCase
{
    private PDO $reader;
    private PDO $writer;
    private PDO $otherWriter;
    private string $schema;
    private string $source;
    private string $counter;
    private string $countSql;
    private static array $measurements = [];
    private \Illuminate\Database\Migrations\Migration $migration;

    public function refreshDatabase(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('127.0.0.1', getenv('DB_HOST'));
        self::assertMatchesRegularExpression('/^most_phpunit_[a-f0-9]{24}_testing$/D', (string) getenv('DB_DATABASE'));
        $this->reader = DB::connection()->getPdo();
        $this->writer = $this->connect();
        $this->otherWriter = $this->connect();
        $this->schema = 'public';
        $this->source = $this->schema.'.estimate_resource_prices';
        $this->counter = $this->schema.'.estimate_resource_price_count_deltas';

        $this->reader->exec('CREATE TABLE '.$this->source.' (id bigint PRIMARY KEY, dataset_version_id bigint NOT NULL, payload text NOT NULL)');
        $this->reader->exec('INSERT INTO '.$this->source." SELECT value, 1, 'fixture' FROM generate_series(1,1000) value");
        $this->migration = require base_path('database/migrations/2026_10_17_030000_add_exact_estimate_resource_price_count.php');
        $this->migration->up();
        $this->countSql = $this->captureProductCountSql();
    }

    protected function tearDown(): void
    {
        while (DB::connection()->transactionLevel() > 0) {
            DB::rollBack();
        }
        foreach (['writer', 'otherWriter', 'reader'] as $property) {
            if (isset($this->{$property}) && $this->{$property}->inTransaction()) {
                $this->{$property}->rollBack();
            }
        }
        if (isset($this->reader, $this->schema)) {
            $this->migration->down();
            $this->reader->exec('DROP TABLE IF EXISTS '.$this->source.' CASCADE');
            $this->reader->exec('DROP TABLE IF EXISTS public.without_counter CASCADE');
        }
        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        $path = getenv('MOST_COUNTER_PROTO_EVIDENCE');
        if (is_string($path) && $path !== '') {
            file_put_contents($path, json_encode(self::$measurements, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
        parent::tearDownAfterClass();
    }

    private function connect(): PDO
    {
        $connection = new PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s', getenv('DB_HOST'), getenv('DB_PORT'), getenv('DB_DATABASE')),
            (string) getenv('DB_USERNAME'), (string) getenv('DB_PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
        $connection->exec("SET statement_timeout='5s'; SET lock_timeout='150ms'; SET idle_in_transaction_session_timeout='15s'");
        return $connection;
    }

    private function captureProductCountSql(): string
    {
        $connection = DB::connection();
        $previous = $connection->getEventDispatcher();
        $dispatcher = new \Illuminate\Events\Dispatcher($this->app);
        $queries = [];
        $dispatcher->listen(\Illuminate\Database\Events\QueryExecuted::class, static function ($event) use (&$queries): void {
            $queries[] = $event->sql;
        });
        $connection->setEventDispatcher($dispatcher);
        try {
            app(EstimateImportStatisticsService::class)->inspect();
        } finally {
            $connection->setEventDispatcher($previous);
        }
        $sum = array_values(array_filter($queries, static fn(string $sql): bool=>str_contains($sql,'COALESCE(sum(delta),0)::bigint')));
        self::assertCount(1, $sum, 'Eligible public inspect must execute the product SUM statement');
        self::assertSame([], array_values(array_filter($queries, static fn(string $sql): bool=>preg_match('/^select count\(\*\) as aggregate from "estimate_resource_prices"$/i',trim($sql)) === 1)));

        return $sum[0];
    }

    private function assertExactCount(PDO $connection, int $expected): void
    {
        self::assertSame((string) $expected, (string) $connection->query('SELECT count(*) FROM '.$this->source)->fetchColumn());
        if ($connection === $this->reader) {
            self::assertSame($expected, app(EstimateImportStatisticsService::class)->inspect()['totals']['prices']);
            return;
        }
        $ownTransaction = !$connection->inTransaction();
        if ($ownTransaction) { $connection->beginTransaction(); }
        try {
            $connection->exec('LOCK TABLE '.$this->source.' IN ACCESS SHARE MODE');
            self::assertSame((string) $expected, (string) $connection->query($this->countSql)->fetchColumn());
            if ($ownTransaction) { $connection->commit(); }
        } catch (\Throwable $exception) {
            if ($ownTransaction && $connection->inTransaction()) { $connection->rollBack(); }
            throw $exception;
        }
    }

    public function test_statement_transitions_rollback_upsert_signed_delete_and_truncate(): void
    {
        $this->assertExactCount($this->reader, 1000);
        $this->writer->exec('INSERT INTO '.$this->source." VALUES (1001,2,'insert') ON CONFLICT(id) DO UPDATE SET payload=excluded.payload");
        $this->writer->exec('INSERT INTO '.$this->source." VALUES (1001,3,'update'),(1002,2,'insert') ON CONFLICT(id) DO UPDATE SET dataset_version_id=excluded.dataset_version_id");
        $this->assertExactCount($this->reader, 1002);
        $this->otherWriter->exec('DELETE FROM '.$this->source.' WHERE id BETWEEN 1 AND 100');
        self::assertTrue((bool) $this->reader->query('SELECT EXISTS(SELECT 1 FROM '.$this->counter.' WHERE delta < 0)')->fetchColumn());
        $this->assertExactCount($this->reader, 902);
        $this->writer->beginTransaction();
        $this->writer->exec('DELETE FROM '.$this->source.' WHERE id=1001');
        $this->writer->exec('SAVEPOINT before_insert');
        $this->writer->exec('INSERT INTO '.$this->source." VALUES(1003,1,'rollback')");
        $this->assertExactCount($this->writer, 902);
        $this->writer->exec('ROLLBACK TO SAVEPOINT before_insert');
        $this->assertExactCount($this->writer, 901);
        $this->assertExactCount($this->reader, 902);
        $this->writer->rollBack();
        $this->assertExactCount($this->reader, 902);
        $this->writer->exec('UPDATE '.$this->source.' SET dataset_version_id=99 WHERE id=1002');
        $this->assertExactCount($this->reader, 902);
        $this->writer->exec('TRUNCATE TABLE '.$this->source);
        $this->assertExactCount($this->reader, 0);
        $this->writer->exec('INSERT INTO '.$this->source." VALUES (1,1,'after truncate')");
        $this->assertExactCount($this->reader, 1);
    }

    public function test_independent_writers_do_not_wait_for_counter_row_and_backend_exit_keeps_delta(): void
    {
        $this->writer->beginTransaction();
        $this->writer->exec('INSERT INTO '.$this->source." VALUES(1001,1,'writer one')");
        $this->otherWriter->beginTransaction();
        $started = hrtime(true);
        $this->otherWriter->exec('INSERT INTO '.$this->source." VALUES(1002,1,'writer two')");
        $elapsed = (hrtime(true) - $started) / 1e6;
        $this->assertExactCount($this->reader, 1000);
        $this->otherWriter->commit();
        $this->assertExactCount($this->reader, 1001);
        $this->writer->commit();
        $this->assertExactCount($this->reader, 1002);
        $slots = (int) $this->reader->query('SELECT count(*) FROM '.$this->counter)->fetchColumn();
        $slotSql = 'SELECT backend_id FROM pg_catalog.pg_stat_get_backend_idset() backend_id WHERE pg_catalog.pg_stat_get_backend_pid(backend_id)=pg_catalog.pg_backend_pid()';
        $oldSlot = (int) $this->otherWriter->query($slotSql)->fetchColumn();
        unset($this->otherWriter);
        $reuseStarted = hrtime(true);
        $reused = false;
        for ($attempt = 1; $attempt <= 128 && hrtime(true)-$reuseStarted < 3_000_000_000; $attempt++) {
            usleep(1000);
            $this->otherWriter = $this->connect();
            if ((int) $this->otherWriter->query($slotSql)->fetchColumn() === $oldSlot) { $reused = true; break; }
            unset($this->otherWriter);
        }
        self::assertTrue($reused, 'No actual backend slot reuse observed within the bounded local experiment');
        self::$measurements['actual_slot_reuse'] = ['observed'=>$reused,'connections'=>$attempt];
        $this->otherWriter->exec('DELETE FROM '.$this->source.' WHERE id=1002');
        $this->assertExactCount($this->reader, 1001);
        self::assertGreaterThanOrEqual($slots, (int) $this->reader->query('SELECT count(*) FROM '.$this->counter)->fetchColumn());
        self::$measurements['independent_writer_ms'] = round($elapsed, 3);
    }

    public function test_repeatable_read_and_serializable_keep_original_count_after_table_rewrite(): void
    {
        foreach (['REPEATABLE READ', 'SERIALIZABLE'] as $isolation) {
            DB::beginTransaction();
            DB::statement('SET TRANSACTION ISOLATION LEVEL '.$isolation);
            $this->reader->query('SELECT pg_catalog.txid_current_snapshot()')->fetchColumn();
            $this->writer->exec('ALTER TABLE '.$this->source.' ALTER COLUMN payload TYPE integer USING length(payload)');
            $this->assertExactCount($this->reader, 0);
            DB::rollBack();
            $this->assertExactCount($this->reader, 1000);
            $this->writer->exec('ALTER TABLE '.$this->source.' ALTER COLUMN payload TYPE text USING payload::text');
        }
    }

    public function test_native_prepared_reader_waits_on_source_before_counter_during_truncate(): void
    {
        foreach ([false, true] as $warm) {
            $script = <<<'PHP'
$config=json_decode(trim(fgets(STDIN)),true,512,JSON_THROW_ON_ERROR);
if(getenv('DB_HOST')!=='127.0.0.1'||!preg_match('/^most_phpunit_[a-f0-9]{24}_testing$/D',(string)getenv('DB_DATABASE'))||(string)getenv('DB_PORT')!==(string)getenv('MOST_POSTGRES_TEST_PORT')){exit(4);}
$db=new PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s',getenv('DB_HOST'),getenv('DB_PORT'),getenv('DB_DATABASE')),(string)getenv('DB_USERNAME'),(string)getenv('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$db->exec("SET statement_timeout='5s'; SET lock_timeout='3s'");
$stmt=$db->prepare($config['sql']);
if($config['warm']){for($i=0;$i<6;$i++){$db->beginTransaction();$db->exec($config['lock']);$stmt->execute();$stmt->fetchColumn();$stmt->closeCursor();$db->commit();}}
echo json_encode(['ready'=>true,'pid'=>(int)$db->query('SELECT pg_backend_pid()')->fetchColumn()])."\n";fflush(STDOUT);
if(trim(fgets(STDIN))!=='GO'){exit(2);}
try{$db->beginTransaction();$db->exec($config['lock']);$stmt->execute();$result=(string)$stmt->fetchColumn();$stmt->closeCursor();$db->commit();echo json_encode(['result'=>$result])."\n";}catch(Throwable){echo "{\"failed\":true}\n";exit(3);}
PHP;
            $environment = getenv();
            foreach (['MOST_POSTGRES_TEST_PROFILE', 'MOST_POSTGRES_TEST_PORT', 'MOST_POSTGRES_TEST_PROJECT'] as $key) {
                $environment[$key] = (string) getenv($key);
            }
            $process = proc_open([PHP_BINARY, '-r', $script], [['pipe','r'],['pipe','w'],['pipe','w']], $pipes, null, $environment);
            self::assertIsResource($process);
            try {
                stream_set_timeout($pipes[1], 8);
                fwrite($pipes[0], json_encode(['sql'=>$this->countSql,'lock'=>'LOCK TABLE '.$this->source.' IN ACCESS SHARE MODE','warm'=>$warm], JSON_THROW_ON_ERROR)."\n");
                fflush($pipes[0]);
                $ready = json_decode((string) fgets($pipes[1]), true, 512, JSON_THROW_ON_ERROR);
                self::assertTrue($ready['ready']);
                $pid = (int) $ready['pid'];
                $this->writer->beginTransaction();
                $this->writer->exec('LOCK TABLE '.$this->source.' IN ACCESS EXCLUSIVE MODE');
                fwrite($pipes[0], "GO\n"); fflush($pipes[0]);
                $waiting = false;
                for ($i = 0; $i < 100; $i++) {
                    $waiting = (bool) $this->reader->query("SELECT EXISTS(SELECT 1 FROM pg_locks WHERE pid=$pid AND relation='{$this->source}'::regclass AND mode='AccessShareLock' AND NOT granted)")->fetchColumn();
                    if ($waiting) { break; }
                    usleep(10000);
                }
                self::assertTrue($waiting);
                self::assertSame(0, (int) $this->reader->query("SELECT count(*) FROM pg_locks WHERE pid=$pid AND relation='{$this->counter}'::regclass AND granted")->fetchColumn());
                $this->writer->exec('TRUNCATE TABLE '.$this->source);
                $this->writer->commit();
                $result = json_decode((string) fgets($pipes[1]), true, 512, JSON_THROW_ON_ERROR);
                self::assertSame('0', $result['result'] ?? null);
                $this->assertExactCount($this->reader, 0);
                self::$measurements[$warm ? 'warm_native_lock_order' : 'cold_native_lock_order'] = 'explicit_source_lock_before_counter';
            } finally {
                if ($this->writer->inTransaction()) { $this->writer->rollBack(); }
                foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
                $status = proc_get_status($process);
                if ($status['running']) { proc_terminate($process); }
                proc_close($process);
            }
            $this->writer->exec('INSERT INTO '.$this->source." VALUES (1,1,'restart')");
        }
    }

    public function test_counter_reads_bounded_rows_instead_of_full_source_scan(): void
    {
        $this->writer->exec('INSERT INTO '.$this->source." SELECT value,1,'fixture' FROM generate_series(1001,200000) value");
        $this->assertExactCount($this->reader, 200000);
        $plans = [];
        foreach (['original'=>'SELECT count(*) FROM '.$this->source, 'counter'=>$this->captureProductCountSql()] as $name=>$sql) {
            $plans[$name] = json_decode((string) $this->reader->query('EXPLAIN (ANALYZE,BUFFERS,TIMING OFF,FORMAT JSON) '.$sql)->fetchColumn(), true, 512, JSON_THROW_ON_ERROR)[0];
        }
        $scanned = static function (array $node) use (&$scanned): int {
            $rows = isset($node['Relation Name']) ? (int) $node['Actual Rows'] * (int) $node['Actual Loops'] : 0;
            foreach ($node['Plans'] ?? [] as $child) { $rows += $scanned($child); }
            return $rows;
        };
        $originalRows = $scanned($plans['original']['Plan']);
        $counterRows = $scanned($plans['counter']['Plan']);
        self::assertGreaterThanOrEqual(200000, $originalRows);
        self::assertLessThan(200, $counterRows);
        self::$measurements['read_plan'] = ['original_rows'=>$originalRows,'counter_rows'=>$counterRows,
            'original_ms'=>$plans['original']['Execution Time'],'counter_ms'=>$plans['counter']['Execution Time']];
    }

    public function test_writer_needs_no_counter_or_trigger_function_privilege(): void
    {
        $role = 'counter_writer_'.bin2hex(random_bytes(8));
        $this->reader->exec('CREATE ROLE '.$role.' NOLOGIN');
        $this->reader->exec('GRANT USAGE ON SCHEMA '.$this->schema.' TO '.$role);
        $this->reader->exec('GRANT SELECT,INSERT,UPDATE,DELETE ON '.$this->source.' TO '.$role);
        try {
            $this->writer->exec('SET ROLE '.$role);
            $this->writer->exec('INSERT INTO '.$this->source." VALUES (1001,1,'limited writer')");
            $this->writer->exec('UPDATE '.$this->source." SET payload='updated' WHERE id=1001");
            $this->assertExactCount($this->reader, 1001);
            try {
                $this->writer->query('SELECT sum(delta) FROM '.$this->counter);
                self::fail('Counter read must not be granted to the source-only writer');
            } catch (\PDOException $exception) {
                self::assertSame('42501', $exception->getCode());
            }
            $this->writer->exec('DELETE FROM '.$this->source.' WHERE id=1001');
            $this->assertExactCount($this->reader, 1000);
        } finally {
            $this->writer->exec('RESET ROLE');
            $this->reader->exec('REVOKE ALL ON '.$this->source.' FROM '.$role);
            $this->reader->exec('REVOKE USAGE ON SCHEMA '.$this->schema.' FROM '.$role);
            $this->reader->exec('DROP ROLE '.$role);
        }
    }

    public function test_always_trigger_preserves_count_for_replica_session_and_int64(): void
    {
        try {
            $this->writer->exec("SET session_replication_role='replica'");
            $this->writer->exec('INSERT INTO '.$this->source." VALUES (1001,1,'replica session')");
            $this->assertExactCount($this->reader, 1001);
            $this->writer->exec('DELETE FROM '.$this->source.' WHERE id=1001');
            $this->assertExactCount($this->reader, 1000);
        } finally {
            $this->writer->exec("SET session_replication_role='origin'");
        }
        DB::beginTransaction();
        try {
            $this->reader->exec('UPDATE '.$this->counter.' SET delta=delta+2147483648 WHERE slot=-1');
            $value = app(EstimateImportStatisticsService::class)->inspect()['totals']['prices'];
            self::assertSame(2147484648, $value);
            self::assertIsInt($value);
        } finally {
            DB::rollBack();
        }
        $this->assertExactCount($this->reader, 1000);
    }

    public function test_rc_backfill_takes_commit_after_guard_snapshot_into_account(): void
    {
        $this->migration->down();
        $this->reader->exec("SET default_transaction_isolation='repeatable read'");
        $connection = DB::connection();
        $previous = $connection->getEventDispatcher();
        $dispatcher = new \Illuminate\Events\Dispatcher($this->app);
        $inserted = false;
        $dispatcher->listen(\Illuminate\Database\Events\QueryExecuted::class, function ($event) use (&$inserted): void {
            if (!$inserted && str_contains($event->sql,'IS NOT NULL AS present')) {
                $inserted = true;
                $this->writer->exec('INSERT INTO '.$this->source." VALUES (1001,1,'committed between presence and lock')");
            }
        });
        $connection->setEventDispatcher($dispatcher);
        try {
            $this->migration->up();
            self::assertTrue($inserted);
            self::assertSame(1001, (int) $this->reader->query('SELECT sum(delta) FROM '.$this->counter)->fetchColumn());
            $this->assertExactCount($this->reader, 1001);
        } finally {
            $connection->setEventDispatcher($previous);
            $this->reader->exec("SET default_transaction_isolation='read committed'");
        }
    }

    public function test_statement_transition_cost_for_bounded_import_batches(): void
    {
        $baseline = $this->schema.'.without_counter';
        $this->reader->exec('CREATE TABLE '.$baseline.' (LIKE '.$this->source.' INCLUDING ALL)');
        $times = ['original'=>0.0, 'counter'=>0.0];
        $wal = ['original'=>0, 'counter'=>0];
        foreach (['original','counter','counter','original'] as $position=>$kind) {
            $table = $kind === 'original' ? $baseline : $this->source;
            $startId = 1001 + 500 * $position;
            $lsn = $this->reader->query('SELECT pg_current_wal_insert_lsn()')->fetchColumn();
            $started = hrtime(true);
            $this->writer->exec('INSERT INTO '.$table." SELECT value,1,repeat(md5(value::text),64) FROM generate_series($startId,".($startId+499).') value');
            $times[$kind] += (hrtime(true) - $started) / 1e6;
            $walStatement = $this->reader->prepare('SELECT pg_wal_lsn_diff(pg_current_wal_insert_lsn(),?::pg_lsn)::bigint');
            $walStatement->execute([$lsn]);
            $wal[$kind] += (int) $walStatement->fetchColumn();
        }
        $this->assertExactCount($this->reader, 2000);
        self::$measurements['bulk_500_rows_2KiB_payload_ABBA_ms'] = array_map(static fn(float $value): float=>round($value,3), $times);
        self::$measurements['bulk_500_rows_ABBA_wal_bytes'] = $wal;
        $singleTimes = ['original'=>0.0, 'counter'=>0.0];
        foreach (['original','counter','counter','original'] as $position=>$kind) {
            $table = $kind === 'original' ? $baseline : $this->source;
            $statement = $this->writer->prepare('INSERT INTO '.$table." VALUES (?,1,repeat(md5(?::text),64))");
            $started = hrtime(true);
            for ($row = 0; $row < 100; $row++) {
                $id = 10000 + $position * 100 + $row;
                $statement->execute([$id,$id]);
            }
            $singleTimes[$kind] += (hrtime(true) - $started) / 1e6;
        }
        $this->assertExactCount($this->reader, 2200);
        self::$measurements['single_row_100_statement_ABBA_ms'] = array_map(static fn(float $value): float=>round($value,3), $singleTimes);
    }

    public function test_actual_service_falls_back_after_rollback_and_migration_can_reapply(): void
    {
        $this->writer->exec('INSERT INTO '.$this->source." VALUES (1001,1,'before rollback')");
        $this->migration->down();
        $this->assertExactCount($this->reader, 1001);
        $this->writer->exec('INSERT INTO '.$this->source." VALUES (1002,1,'without counter')");
        $this->assertExactCount($this->reader, 1002);
        $this->migration->up();
        $this->assertExactCount($this->reader, 1002);
        self::assertSame(1, $this->reader->query('SELECT count(*) FROM '.$this->counter)->fetchColumn());
    }

    public function test_migration_no_op_without_source_can_roll_back(): void
    {
        $this->migration->down();
        $this->reader->exec('DROP TABLE '.$this->source);
        $this->migration->up();
        $this->migration->down();
        self::assertSame(0, app(EstimateImportStatisticsService::class)->inspect()['totals']['prices']);
    }

    public function test_migration_rejects_existing_raw_and_laravel_transactions(): void
    {
        foreach ([false,true] as $managed) {
            $managed ? DB::beginTransaction() : $this->reader->beginTransaction();
            try {
                $this->migration->up();
                self::fail('Migration accepted an existing transaction');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('requires its own transaction', $exception->getMessage());
            } finally {
                $managed ? DB::rollBack() : $this->reader->rollBack();
            }
        }
        $this->assertExactCount($this->reader, 1000);
    }

    public function test_service_preserves_source_only_read_rights_and_rls(): void
    {
        $role = 'counter_reader_'.bin2hex(random_bytes(8));
        $this->reader->exec('CREATE ROLE '.$role.' NOLOGIN');
        $this->reader->exec('GRANT USAGE ON SCHEMA public TO '.$role);
        $this->reader->exec('GRANT SELECT ON '.$this->source.' TO '.$role);
        try {
            $this->reader->exec('SET ROLE '.$role);
            self::assertSame(1000, app(EstimateImportStatisticsService::class)->inspect()['totals']['prices']);
            $this->reader->exec('RESET ROLE');
            $this->reader->exec('GRANT SELECT ON '.$this->counter.' TO '.$role);
            $this->reader->exec('ALTER TABLE '.$this->source.' ENABLE ROW LEVEL SECURITY');
            $this->reader->exec('CREATE POLICY selected_prices ON '.$this->source.' FOR SELECT TO '.$role.' USING(id <= 10)');
            $this->reader->exec('SET ROLE '.$role);
            self::assertSame(10, app(EstimateImportStatisticsService::class)->inspect()['totals']['prices']);
        } finally {
            $this->reader->exec('RESET ROLE');
            $this->reader->exec('DROP POLICY IF EXISTS selected_prices ON '.$this->source);
            $this->reader->exec('ALTER TABLE '.$this->source.' DISABLE ROW LEVEL SECURITY');
            $this->reader->exec('REVOKE ALL ON '.$this->source.','.$this->counter.' FROM '.$role);
            $this->reader->exec('REVOKE USAGE ON SCHEMA public FROM '.$role);
            $this->reader->exec('DROP ROLE '.$role);
        }
    }

    public function test_other_after_truncate_writers_are_included_in_exact_count(): void
    {
        $this->reader->exec("CREATE FUNCTION public.counter_truncate_fixture() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN INSERT INTO public.estimate_resource_prices VALUES(CASE WHEN TG_NAME LIKE 'aaa%' THEN 1001 ELSE 1002 END,1,'truncate writer'); RETURN NULL; END; \$\$");
        try {
            foreach (['aaa_count_fixture','zzz_count_fixture'] as $name) {
                $this->reader->exec('CREATE TRIGGER '.$name.' AFTER TRUNCATE ON '.$this->source.' FOR EACH STATEMENT EXECUTE FUNCTION public.counter_truncate_fixture()');
            }
            $this->writer->exec('TRUNCATE TABLE '.$this->source);
            $this->assertExactCount($this->reader, 2);
        } finally {
            $this->reader->exec('DROP TRIGGER IF EXISTS aaa_count_fixture ON '.$this->source);
            $this->reader->exec('DROP TRIGGER IF EXISTS zzz_count_fixture ON '.$this->source);
            $this->reader->exec('DROP FUNCTION public.counter_truncate_fixture()');
        }
    }
}
