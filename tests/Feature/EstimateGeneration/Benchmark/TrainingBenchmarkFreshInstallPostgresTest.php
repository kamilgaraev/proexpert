<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Benchmark;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('postgres-contract')]
final class TrainingBenchmarkFreshInstallPostgresTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('RUN_POSTGRES_TRAINING_BENCHMARK_CONTRACT') !== '1'
            || getenv('DB_CONNECTION') !== 'pgsql'
            || ! str_ends_with((string) getenv('DB_DATABASE'), '_contract')) {
            self::markTestSkipped('Requires explicit disposable PostgreSQL training/benchmark contract database.');
        }

        $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    }

    protected function tearDown(): void
    {
        \Illuminate\Foundation\Bootstrap\HandleExceptions::flushState($this);
        \Illuminate\Support\Facades\Facade::clearResolvedInstances();
        parent::tearDown();
    }

    public function test_complete_production_inventory_reaches_final_training_catalog(): void
    {
        self::assertSame('pgsql', DB::getDriverName());
        self::assertStringEndsWith('_contract', (string) DB::getDatabaseName());
        self::assertTrue(Schema::hasTable('estimate_generation_training_datasets'));
        self::assertTrue(Schema::hasTable('estimate_generation_training_examples'));
        self::assertTrue(Schema::hasTable('estimate_generation_benchmark_runs'));
        foreach (['dataset_key', 'version', 'dataset_type', 'scope', 'processing_token', 'processing_lease_expires_at', 'processing_attempt'] as $column) {
            self::assertTrue(Schema::hasColumn('estimate_generation_training_datasets', $column), $column);
        }
        foreach (['eg_training_dataset_key_version_uq', 'eg_training_dataset_membership_uq', 'eg_training_processing_lease_idx'] as $index) {
            $catalog = DB::selectOne('SELECT i.indisvalid, i.indisready FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = ?', [$index]);
            self::assertNotNull($catalog, $index);
            self::assertTrue((bool) $catalog->indisvalid, $index);
            self::assertTrue((bool) $catalog->indisready, $index);
        }
        foreach (['eg_training_example_membership_fk', 'eg_training_processing_lease_chk', 'eg_benchmark_closed_state_chk'] as $constraint) {
            self::assertTrue((bool) DB::table('pg_constraint')->where('conname', $constraint)->value('convalidated'), $constraint);
        }
    }
}
