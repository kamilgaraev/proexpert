<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting\Waves23;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Reporting\PostgresProcessRaceHarness;
use Tests\TestCase;

final class ReportingSealRacePostgresTest extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 5).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    public function test_row_insert_and_seal_are_serialized_on_parent_snapshot(): void
    {
        if (getenv('RUN_REPORTING_PG_RACE') !== '1') {
            self::markTestSkipped('Set RUN_REPORTING_PG_RACE=1 on an isolated PostgreSQL test database.');
        }
        $suffix = strtolower(Str::random(12));
        $snapshots = 'test_reporting_snapshots_'.$suffix;
        $rows = 'test_reporting_rows_'.$suffix;
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'most-report-seal-'.$suffix;
        $race = new PostgresProcessRaceHarness($directory);

        try {
            DB::statement("CREATE TABLE {$snapshots} (id text PRIMARY KEY, row_count bigint NOT NULL, output_hash char(64) NOT NULL, sealed_at timestamptz NULL, sealed_content_digest char(64) NULL)");
            DB::statement("CREATE TABLE {$rows} (id bigserial PRIMARY KEY, organization_id bigint NOT NULL, snapshot_id text NOT NULL REFERENCES {$snapshots}(id), row_key text NOT NULL, value text NOT NULL)");
            DB::statement("CREATE TRIGGER {$snapshots}_sealed BEFORE INSERT OR UPDATE OR DELETE ON {$snapshots} FOR EACH ROW EXECUTE FUNCTION sealed_reporting_snapshot_guard('{$rows}')");
            DB::statement("CREATE TRIGGER {$rows}_sealed BEFORE INSERT OR UPDATE OR DELETE ON {$rows} FOR EACH ROW EXECUTE FUNCTION sealed_reporting_row_guard('{$snapshots}')");
            DB::table($snapshots)->insert([
                'id' => 'snapshot-1',
                'row_count' => 2,
                'output_hash' => str_repeat('0', 64),
            ]);
            DB::table($rows)->insert([
                'organization_id' => 1,
                'snapshot_id' => 'snapshot-1',
                'row_key' => 'row-1',
                'value' => 'first',
            ]);

            $insert = $race->spawn(1, static function () use ($rows, $directory): array {
                DB::beginTransaction();
                DB::table($rows)->insert([
                    'organization_id' => 1,
                    'snapshot_id' => 'snapshot-1',
                    'row_key' => 'row-2',
                    'value' => 'second',
                ]);
                file_put_contents($directory.DIRECTORY_SEPARATOR.'insert-locked', 'yes');
                usleep(750000);
                DB::commit();

                return ['inserted' => true];
            });
            $race->release(1);
            $deadline = microtime(true) + 10;
            while (! is_file($directory.DIRECTORY_SEPARATOR.'insert-locked') && microtime(true) < $deadline) {
                usleep(10000);
            }
            self::assertFileExists($directory.DIRECTORY_SEPARATOR.'insert-locked');

            $seal = $race->spawn(2, static function () use ($snapshots, $rows): array {
                DB::table($snapshots)->where('id', 'snapshot-1')->update([
                    'sealed_at' => now(),
                    'output_hash' => DB::raw("reporting_persisted_rows_digest('{$rows}', id)"),
                    'sealed_content_digest' => DB::raw("reporting_persisted_rows_digest('{$rows}', id)"),
                ]);

                return ['sealed' => true];
            });
            $race->release(2);
            $sealBackend = $race->waitForWorkerBackendPid(2);
            $observer = $race->independentConnection('reporting_seal_race_observer');
            $race->waitForPostgresWait($observer, $sealBackend);
            $race->waitForChildren([$insert, $seal]);

            self::assertTrue($race->result(1)['inserted']);
            self::assertTrue($race->result(2)['sealed']);
            $snapshot = DB::table($snapshots)->where('id', 'snapshot-1')->first();
            self::assertNotNull($snapshot->sealed_at);
            self::assertSame((string) $snapshot->output_hash, (string) $snapshot->sealed_content_digest);
            self::assertSame(2, DB::table($rows)->count());
        } finally {
            DB::statement("DROP TABLE IF EXISTS {$rows}");
            DB::statement("DROP TABLE IF EXISTS {$snapshots}");
            $race->cleanup();
        }
    }
}
