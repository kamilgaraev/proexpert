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

        try {
            (new TrainingBenchmarkOnlineMigrationRuntime)->ensureConcurrentIndex(
                'eg_online_index_probe_uq',
                'CREATE UNIQUE INDEX CONCURRENTLY eg_online_index_probe_uq ON eg_online_index_probe (id)',
            );
            self::fail('A valid index with the wrong definition was adopted.');
        } catch (\RuntimeException $exception) {
            self::assertSame('estimate_generation_online_migration_index_definition_mismatch', $exception->getMessage());
        }

        foreach ([
            'CREATE INDEX CONCURRENTLY eg_online_index_probe_uq ON eg_online_index_probe (value)',
            'CREATE UNIQUE INDEX CONCURRENTLY eg_online_index_probe_uq ON eg_online_index_other (value)',
            'CREATE UNIQUE INDEX CONCURRENTLY eg_online_index_probe_uq ON eg_online_index_probe (value DESC NULLS LAST)',
        ] as $wrongDefinition) {
            if (! Schema::hasTable('eg_online_index_other')) {
                DB::statement('CREATE TABLE eg_online_index_other (id bigint PRIMARY KEY, value integer NOT NULL)');
            }
            try {
                (new TrainingBenchmarkOnlineMigrationRuntime)->ensureConcurrentIndex('eg_online_index_probe_uq', $wrongDefinition);
                self::fail('A valid index with wrong uniqueness or table was adopted.');
            } catch (\RuntimeException $exception) {
                self::assertSame('estimate_generation_online_migration_index_definition_mismatch', $exception->getMessage());
            }
        }

        DB::statement('ALTER TABLE eg_online_index_probe ADD COLUMN label text');
        (new TrainingBenchmarkOnlineMigrationRuntime)->ensureConcurrentIndex('eg_online_index_label_idx', 'CREATE INDEX CONCURRENTLY eg_online_index_label_idx ON eg_online_index_probe (label)');
        try {
            (new TrainingBenchmarkOnlineMigrationRuntime)->ensureConcurrentIndex('eg_online_index_label_idx', 'CREATE INDEX CONCURRENTLY eg_online_index_label_idx ON eg_online_index_probe (label COLLATE "C")');
            self::fail('A valid index with the wrong collation was adopted.');
        } catch (\RuntimeException $exception) {
            self::assertSame('estimate_generation_online_migration_index_definition_mismatch', $exception->getMessage());
        }
        try {
            (new TrainingBenchmarkOnlineMigrationRuntime)->ensureConcurrentIndex('eg_online_index_label_idx', 'CREATE INDEX CONCURRENTLY eg_online_index_label_idx ON eg_online_index_probe (label text_pattern_ops)');
            self::fail('A valid index with the wrong operator class was adopted.');
        } catch (\RuntimeException $exception) {
            self::assertSame('estimate_generation_online_migration_index_definition_mismatch', $exception->getMessage());
        }

        DB::statement('CREATE SCHEMA eg_online_probe');
        DB::statement('CREATE TABLE eg_online_probe.eg_online_index_probe (id bigint PRIMARY KEY, value integer NOT NULL)');
        DB::statement('INSERT INTO eg_online_probe.eg_online_index_probe (id, value) VALUES (1, 1), (2, 1)');
        try {
            DB::statement('CREATE UNIQUE INDEX CONCURRENTLY eg_online_index_probe_uq ON eg_online_probe.eg_online_index_probe (value)');
            self::fail('Duplicate schema-qualified rows unexpectedly produced a valid unique index.');
        } catch (\Illuminate\Database\QueryException) {
            self::assertFalse((bool) DB::selectOne("SELECT i.indisvalid FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE c.relname = 'eg_online_index_probe_uq' AND n.nspname = 'eg_online_probe'")->indisvalid);
        }
        DB::table('eg_online_probe.eg_online_index_probe')->where('id', 2)->delete();
        (new TrainingBenchmarkOnlineMigrationRuntime)->ensureConcurrentIndex(
            'eg_online_index_probe_uq',
            'CREATE UNIQUE INDEX CONCURRENTLY eg_online_index_probe_uq ON eg_online_probe.eg_online_index_probe (value)',
        );
        self::assertTrue((bool) DB::selectOne("SELECT i.indisvalid FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE c.relname = 'eg_online_index_probe_uq' AND n.nspname = 'eg_online_probe'")->indisvalid);
        DB::statement('DROP SCHEMA eg_online_probe CASCADE');
    }

    public function test_session_timeouts_are_restored_after_success_and_failure(): void
    {
        DB::statement("SET lock_timeout = '1700ms'");
        DB::statement("SET statement_timeout = '23s'");
        $runtime = new TrainingBenchmarkOnlineMigrationRuntime;

        foreach ([false, true] as $fail) {
            $previous = $runtime->configureSessionTimeouts();
            try {
                self::assertSame('5s', DB::selectOne("SELECT current_setting('lock_timeout') AS value")->value);
                if ($fail) {
                    throw new \RuntimeException('injected');
                }
            } catch (\RuntimeException $exception) {
                self::assertSame('injected', $exception->getMessage());
            } finally {
                $runtime->restoreSessionTimeouts($previous);
            }
            self::assertSame('1700ms', DB::selectOne("SELECT current_setting('lock_timeout') AS value")->value);
            self::assertSame('23s', DB::selectOne("SELECT current_setting('statement_timeout') AS value")->value);
        }

        putenv('ESTIMATE_CONTRACT_FAIL_SECOND_TIMEOUT_SET=1');
        try {
            $runtime->configureSessionTimeouts();
            self::fail('The injected second SET failure was not propagated.');
        } catch (\RuntimeException $exception) {
            self::assertSame('estimate_generation_online_migration_second_timeout_set_failed', $exception->getMessage());
        } finally {
            putenv('ESTIMATE_CONTRACT_FAIL_SECOND_TIMEOUT_SET');
        }
        self::assertSame('1700ms', DB::selectOne("SELECT current_setting('lock_timeout') AS value")->value);
        self::assertSame('23s', DB::selectOne("SELECT current_setting('statement_timeout') AS value")->value);
    }

    public function test_constraint_catalog_operations_are_schema_isolated(): void
    {
        DB::statement('CREATE SCHEMA eg_constraint_a');
        DB::statement('CREATE SCHEMA eg_constraint_b');
        DB::statement('CREATE TABLE eg_constraint_a.probe (id integer)');
        DB::statement('CREATE TABLE eg_constraint_b.probe (id integer)');
        $runtime = new TrainingBenchmarkOnlineMigrationRuntime;
        $runtime->ensureConstraint('probe', 'probe_positive_chk', 'CHECK (id > 0)', 'eg_constraint_a');
        $runtime->ensureConstraint('probe', 'probe_positive_chk', 'CHECK (id >= 0)', 'eg_constraint_b');
        $runtime->validateConstraint('probe', 'probe_positive_chk', 'eg_constraint_a');
        $runtime->validateConstraint('probe', 'probe_positive_chk', 'eg_constraint_b');

        self::assertSame(2, (int) DB::selectOne("SELECT count(*) AS count FROM pg_constraint WHERE conname = 'probe_positive_chk'")->count);
        self::assertSame(1, (int) DB::selectOne("SELECT count(*) AS count FROM pg_constraint c JOIN pg_class t ON t.oid = c.conrelid JOIN pg_namespace n ON n.oid = t.relnamespace WHERE c.conname = 'probe_positive_chk' AND n.nspname = 'eg_constraint_a' AND c.convalidated")->count);
        DB::statement('DROP SCHEMA eg_constraint_a CASCADE');
        DB::statement('DROP SCHEMA eg_constraint_b CASCADE');
    }
}
