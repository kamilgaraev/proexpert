<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Benchmark;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('postgres-contract')]
final class TrainingBenchmarkPostgresContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    }

    protected function tearDown(): void
    {
        \Illuminate\Foundation\Bootstrap\HandleExceptions::flushState($this);
        \Illuminate\Support\Facades\Facade::clearResolvedInstances();
        parent::tearDown();
    }

    public function test_versioned_training_and_benchmark_database_contract_is_closed_and_immutable(): void
    {
        $this->requireDisposablePostgres();
        $this->ensureLegacyTrainingSchema();
        $migration = require dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_12_001700_rebuild_estimate_generation_training_and_benchmarks.php';
        if (\Illuminate\Support\Facades\Schema::hasColumn('estimate_generation_training_datasets', 'dataset_key')) {
            $migration->down();
        }
        self::assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('estimate_generation_training_datasets', 'dataset_key'));
        $migration->up();

        $organizationId = (int) DB::table('organizations')->insertGetId([]);
        $otherOrganizationId = (int) DB::table('organizations')->insertGetId([]);
        $reviewerId = (int) DB::table('system_admins')->insertGetId([]);
        $key = fake()->uuid();

        $datasetId = $this->insertDataset($organizationId, $reviewerId, $key, 1, 'acceptance', 'draft');
        $exampleId = DB::table('estimate_generation_training_examples')->insertGetId([
            'training_dataset_id' => $datasetId,
            'source_row_hash' => hash('sha256', 'contract-row'),
            'work_name' => 'Работа',
            'status' => 'accepted',
            'reviewed_by' => $reviewerId,
            'reviewed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('estimate_generation_training_datasets')->where('id', $datasetId)->update([
            'status' => 'approved', 'approved_by' => $reviewerId, 'approved_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertRejected(fn () => DB::table('estimate_generation_training_examples')->where('id', $exampleId)->update(['work_name' => 'Изменено']));
        $this->assertRejected(fn () => DB::table('estimate_generation_training_examples')->insert([
            'training_dataset_id' => $datasetId, 'source_row_hash' => hash('sha256', 'late-row'),
            'work_name' => 'Поздняя работа', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]));
        $this->assertRejected(fn () => DB::table('estimate_generation_training_datasets')->where('id', $datasetId)->update(['title' => 'Изменено']));
        $this->assertRejected(fn () => $this->insertRun($otherOrganizationId, $datasetId, 1, 'cross-org'));
        $this->assertRejected(fn () => $this->insertRun($organizationId, $datasetId, 2, 'wrong-version'));

        $runId = $this->insertRun($organizationId, $datasetId, 1, 'valid');
        DB::table('estimate_generation_benchmark_runs')->where('id', $runId)->update([
            'status' => 'completed', 'metrics' => '{}', 'case_results' => '[]', 'duration_ms' => 10,
            'completed_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertRejected(fn () => DB::table('estimate_generation_benchmark_runs')->where('id', $runId)->update(['pipeline_version' => 'mutated']));
        $this->assertRejected(fn () => DB::table('estimate_generation_benchmark_runs')->where('id', $runId)->delete());

        $developmentId = $this->insertDataset($organizationId, $reviewerId, $key, 2, 'development');
        self::assertSame(2, (int) DB::table('estimate_generation_training_datasets')->where('id', $developmentId)->value('version'));
        $this->assertRejected(fn () => $this->insertDataset($organizationId, $reviewerId, $key, 2, 'development'));
        $this->assertRejected(fn () => DB::table('estimate_generation_training_examples')->insert([
            'training_dataset_id' => $developmentId, 'source_row_hash' => hash('sha256', 'unreviewed'),
            'work_name' => 'Без проверки', 'status' => 'accepted', 'created_at' => now(), 'updated_at' => now(),
        ]));

        $migration->down();
        $migration->up();
        self::assertTrue(\Illuminate\Support\Facades\Schema::hasTable('estimate_generation_benchmark_runs'));
    }

    private function insertDataset(int $organizationId, int $reviewerId, string $key, int $version, string $type, string $status = 'approved'): int
    {
        return DB::table('estimate_generation_training_datasets')->insertGetId([
            'uuid' => fake()->uuid(), 'dataset_key' => $key, 'version' => $version, 'dataset_type' => $type,
            'scope' => 'organization', 'organization_id' => $organizationId, 'title' => 'Contract dataset',
            'source_system' => 'contract', 'status' => $status, 'quality_status' => 'accepted',
            'source_quality_score' => '1.0000', 'approved_by' => $status === 'approved' ? $reviewerId : null,
            'approved_at' => $status === 'approved' ? now() : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function insertRun(int $organizationId, int $datasetId, int $version, string $key): int
    {
        return DB::table('estimate_generation_benchmark_runs')->insertGetId([
            'uuid' => fake()->uuid(), 'idempotency_key' => $key, 'organization_id' => $organizationId,
            'training_dataset_id' => $datasetId, 'dataset_version' => $version, 'pipeline_version' => 'pipeline:v1',
            'model_versions' => '{}', 'normative_version' => 'norm:v1', 'price_version' => 'price:v1',
            'cost_amount' => '0.10000000', 'currency' => 'RUB', 'status' => 'running', 'started_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function assertRejected(callable $operation): void
    {
        try {
            $operation();
            self::fail('PostgreSQL contract accepted a forbidden mutation.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }

    private function requireDisposablePostgres(): void
    {
        $database = (string) DB::connection()->getDatabaseName();
        if (getenv('RUN_POSTGRES_TRAINING_BENCHMARK_CONTRACT') !== '1' || DB::getDriverName() !== 'pgsql' || ! str_ends_with($database, '_contract')) {
            self::markTestSkipped('Requires explicit disposable PostgreSQL training/benchmark contract database.');
        }
    }

    private function ensureLegacyTrainingSchema(): void
    {
        $schema = \Illuminate\Support\Facades\Schema::getFacadeRoot();
        foreach (['organizations', 'projects', 'system_admins'] as $tableName) {
            if (! $schema->hasTable($tableName)) {
                $schema->create($tableName, static function (\Illuminate\Database\Schema\Blueprint $table): void {
                    $table->id();
                });
            }
        }
        if (! $schema->hasTable('estimate_generation_learning_examples')) {
            $schema->create('estimate_generation_learning_examples', static function (\Illuminate\Database\Schema\Blueprint $table): void {
                $table->id();
            });
        }
        if ($schema->hasTable('estimate_generation_training_datasets') && ! $schema->hasTable('estimate_generation_training_examples')) {
            $schema->dropIfExists('estimate_generation_training_files');
            $schema->dropIfExists('estimate_generation_training_datasets');
        }
        if ($schema->hasTable('estimate_generation_training_examples')
            && $schema->hasColumn('estimate_generation_training_examples', 'reviewed_at')
            && ! $schema->hasColumn('estimate_generation_training_datasets', 'dataset_key')) {
            $schema->table('estimate_generation_training_examples', static function (\Illuminate\Database\Schema\Blueprint $table): void {
                $table->dropColumn('reviewed_at');
            });
        }
        if (! $schema->hasTable('estimate_generation_training_datasets')) {
            $migration = require dirname(__DIR__, 4).'/database/migrations/2026_06_28_000004_create_estimate_generation_training_dataset_tables.php';
            $migration->up();
        }
    }
}
