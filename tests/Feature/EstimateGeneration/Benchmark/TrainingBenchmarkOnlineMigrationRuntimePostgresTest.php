<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Benchmark;

use App\BusinessModules\Addons\EstimateGeneration\Support\TrainingBenchmarkOnlineMigrationRuntime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('postgres-contract')]
final class TrainingBenchmarkOnlineMigrationRuntimePostgresTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        if (getenv('RUN_POSTGRES_TRAINING_BENCHMARK_CONTRACT') !== '1' || DB::getDriverName() !== 'pgsql' || ! str_ends_with((string) DB::getDatabaseName(), '_contract')) {
            self::markTestSkipped('Requires the disposable training benchmark PostgreSQL contract database.');
        }
        if (! Schema::hasTable('system_admins')) {
            Schema::create('system_admins', static function (\Illuminate\Database\Schema\Blueprint $table): void {
                $table->id();
            });
        }
        if (! Schema::hasTable('estimate_generation_training_datasets')) {
            (require dirname(__DIR__, 4).'/database/migrations/2026_06_28_000004_create_estimate_generation_training_dataset_tables.php')->up();
        }
    }

    protected function tearDown(): void
    {
        \Illuminate\Foundation\Bootstrap\HandleExceptions::flushState($this);
        \Illuminate\Support\Facades\Facade::clearResolvedInstances();
        parent::tearDown();
    }

    public function test_bounded_backfill_resumes_after_an_interruption(): void
    {
        DB::statement('ALTER TABLE estimate_generation_training_datasets ADD COLUMN dataset_key uuid, ADD COLUMN version integer, ADD COLUMN dataset_type text, ADD COLUMN scope text, ADD COLUMN processing_token uuid, ADD COLUMN processing_lease_expires_at timestamptz');
        DB::statement('ALTER TABLE estimate_generation_training_examples ADD COLUMN reviewed_by bigint, ADD COLUMN reviewed_at timestamptz, ADD COLUMN organization_id bigint, ADD COLUMN dataset_version integer');
        $organizationId = DB::table('organizations')->insertGetId(['name' => 'Online phase contract']);
        $datasetId = DB::table('estimate_generation_training_datasets')->insertGetId(['uuid' => fake()->uuid(), 'organization_id' => $organizationId, 'title' => 'Online phase', 'status' => 'failed', 'created_at' => now(), 'updated_at' => now()]);
        $exampleId = DB::table('estimate_generation_training_examples')->insertGetId(['training_dataset_id' => $datasetId, 'source_row_hash' => hash('sha256', 'online-phase'), 'work_name' => 'Online phase', 'status' => 'accepted', 'created_at' => now(), 'updated_at' => now()]);
        $runtime = new TrainingBenchmarkOnlineMigrationRuntime;

        foreach (['backfillDatasets', 'backfillExamples', 'backfillMembership', 'backfillProcessingLeases'] as $phase) {
            if ($phase === 'backfillExamples') {
                DB::table('estimate_generation_training_examples')->where('id', $exampleId)->update(['status' => 'accepted', 'reviewed_by' => null, 'reviewed_at' => null]);
            } elseif ($phase === 'backfillMembership') {
                DB::table('estimate_generation_training_examples')->where('id', $exampleId)->update(['organization_id' => null, 'dataset_version' => null]);
            } elseif ($phase === 'backfillProcessingLeases') {
                DB::table('estimate_generation_training_datasets')->where('id', $datasetId)->update(['status' => 'processing', 'processing_token' => fake()->uuid(), 'processing_lease_expires_at' => now()->subMinute()]);
            }
            try {
                $runtime->{$phase}(static fn (int $batch): bool => $batch === 1);
                self::fail($phase.' did not propagate the injected interruption.');
            } catch (\RuntimeException $exception) {
                self::assertSame('estimate_generation_online_migration_interrupted', $exception->getMessage());
            }
            $runtime->{$phase}();
        }

        self::assertNotNull(DB::table('estimate_generation_training_datasets')->where('id', $datasetId)->value('dataset_key'));
        self::assertSame('pending', DB::table('estimate_generation_training_examples')->where('id', $exampleId)->value('status'));
        self::assertSame($organizationId, (int) DB::table('estimate_generation_training_examples')->where('id', $exampleId)->value('organization_id'));
        self::assertSame('draft', DB::table('estimate_generation_training_datasets')->where('id', $datasetId)->value('status'));

        DB::table('estimate_generation_training_examples')->where('id', $exampleId)->delete();
        DB::table('estimate_generation_training_datasets')->where('id', $datasetId)->delete();
        DB::table('organizations')->where('id', $organizationId)->delete();
        DB::statement('ALTER TABLE estimate_generation_training_examples DROP COLUMN reviewed_by, DROP COLUMN reviewed_at, DROP COLUMN organization_id, DROP COLUMN dataset_version');
        DB::statement('ALTER TABLE estimate_generation_training_datasets DROP COLUMN dataset_key, DROP COLUMN version, DROP COLUMN dataset_type, DROP COLUMN scope, DROP COLUMN processing_token, DROP COLUMN processing_lease_expires_at');
    }

    public function test_invalid_concurrent_index_is_rebuilt_and_catalog_valid(): void
    {
        DB::statement('CREATE TABLE eg_online_index_probe (id bigint PRIMARY KEY, value integer NOT NULL)');
        DB::statement('INSERT INTO eg_online_index_probe (id, value) VALUES (1, 1), (2, 1)');
        try {
            DB::statement('CREATE UNIQUE INDEX CONCURRENTLY eg_online_index_probe_uq ON eg_online_index_probe (value)');
            self::fail('Duplicate rows unexpectedly produced a valid unique index.');
        } catch (\Illuminate\Database\QueryException) {
            self::assertFalse((bool) DB::table('pg_index')->where('indexrelid', DB::raw("'eg_online_index_probe_uq'::regclass"))->value('indisvalid'));
        }
        DB::table('eg_online_index_probe')->where('id', 2)->delete();

        (new TrainingBenchmarkOnlineMigrationRuntime)->ensureConcurrentIndex(
            'eg_online_index_probe_uq',
            'CREATE UNIQUE INDEX CONCURRENTLY eg_online_index_probe_uq ON eg_online_index_probe (value)',
        );

        $catalog = DB::selectOne("SELECT indisvalid, indisready FROM pg_index WHERE indexrelid = 'eg_online_index_probe_uq'::regclass");
        self::assertTrue((bool) $catalog->indisvalid);
        self::assertTrue((bool) $catalog->indisready);
    }
}
