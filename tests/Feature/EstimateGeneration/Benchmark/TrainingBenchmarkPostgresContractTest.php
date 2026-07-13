<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Benchmark;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkRunRepository;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationTrainingDatasetJob;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\RecoverExpiredTrainingDatasetLeasesJob;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationTrainingDataset;
use App\BusinessModules\Addons\EstimateGeneration\Services\Training\EstimateGenerationTrainingDatasetService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\EstimateGeneration\EstimateGenerationContractDatabaseProvisioner;

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
        $root = dirname(__DIR__, 4);
        $subjects = array_map(static fn (string $basename): object => require EstimateGenerationContractDatabaseProvisioner::subjectMigration('training', $basename, $root), [
            '2026_07_12_001700_rebuild_estimate_generation_training_and_benchmarks.php',
            '2026_07_12_001800_harden_estimate_generation_training_and_benchmarks.php',
            '2026_07_12_001900_close_training_benchmark_edge_contracts.php',
            '2026_07_12_002000_enforce_training_benchmark_storage_contracts.php',
            '2026_07_12_002100_finalize_training_benchmark_architecture.php',
            '2026_07_12_002200_close_training_benchmark_races.php',
        ]);
        [$migration, $hardening, $edgeHardening, $storageHardening, $finalHardening, $raceHardening] = $subjects;
        if (\Illuminate\Support\Facades\Schema::hasColumn('estimate_generation_training_datasets', 'dataset_key')) {
            if (\Illuminate\Support\Facades\Schema::hasColumn('estimate_generation_training_datasets', 'processing_lease_expires_at')) {
                if (DB::selectOne("SELECT 1 FROM pg_constraint WHERE conname = 'eg_benchmark_closed_state_chk'") !== null) {
                    $raceHardening->down();
                }
                $finalHardening->down();
            }
            if (\Illuminate\Support\Facades\Schema::hasColumn('estimate_generation_training_datasets', 'processing_token')
                && ! \Illuminate\Support\Facades\Schema::hasColumn('estimate_generation_benchmark_runs', 'case_results_version_scheme')) {
                DB::statement('ALTER TABLE estimate_generation_training_datasets DROP COLUMN processing_token');
            }
            if (\Illuminate\Support\Facades\Schema::hasColumn('estimate_generation_benchmark_runs', 'case_results_version_scheme')) {
                $storageHardening->down();
            }
            if (\Illuminate\Support\Facades\Schema::hasColumn('estimate_generation_benchmark_runs', 'case_results_sha256')) {
                $edgeHardening->down();
            }
            if (\Illuminate\Support\Facades\Schema::hasColumn('estimate_generation_training_examples', 'dataset_version')) {
                $hardening->down();
            }
            $migration->down();
        }
        self::assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('estimate_generation_training_datasets', 'dataset_key'));
        $this->runMigrationWithInterruptions($migration, ['001700_structure', '001700_backfill', '001700_indexes', '001700_constraints']);
        $this->runMigrationWithInterruptions($hardening, ['001800_structure', '001800_backfill', '001800_indexes', '001800_constraints']);
        $this->runMigrationWithInterruptions($edgeHardening, ['001900_structure', '001900_constraints']);
        $this->runMigrationWithInterruptions($storageHardening, ['002000_structure', '002000_indexes', '002000_constraints']);
        $this->runMigrationWithInterruptions($finalHardening, ['002100_structure', '002100_backfill', '002100_constraints']);
        $this->runMigrationWithInterruptions($raceHardening, ['002200_structure', '002200_constraints']);

        $organizationId = (int) DB::table('organizations')->insertGetId(['name' => 'Contract organization A']);
        $otherOrganizationId = (int) DB::table('organizations')->insertGetId(['name' => 'Contract organization B']);
        $reviewerId = (int) DB::table('system_admins')->insertGetId([]);
        $key = fake()->uuid();

        $datasetId = $this->insertDataset($organizationId, $reviewerId, $key, 1, 'acceptance', 'draft');
        $exampleId = DB::table('estimate_generation_training_examples')->insertGetId([
            'training_dataset_id' => $datasetId,
            'organization_id' => $organizationId,
            'dataset_version' => 1,
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

        $manifest = ['organization_id' => $organizationId, 'pipeline_version' => 'pipeline:v1', 'model_versions' => ['vision' => 'v1'], 'normative_version' => 'norm:v1', 'price_version' => 'price:v1', 'currency' => 'RUB'];
        $benchmarkOutputs = $this->runConcurrentWriters('benchmark', $datasetId, $organizationId, 'concurrent-benchmark', $manifest);
        self::assertSame(1, DB::table('estimate_generation_benchmark_runs')->where('organization_id', $organizationId)->where('idempotency_key', 'concurrent-benchmark')->count());
        self::assertSame($this->doneValue($benchmarkOutputs[0]), $this->doneValue($benchmarkOutputs[1]));
        try {
            app(BenchmarkRunRepository::class)->start(EstimateGenerationTrainingDataset::query()->findOrFail($datasetId), [...$manifest, 'pipeline_version' => 'pipeline:v2'], 'concurrent-benchmark');
            self::fail('Different manifest reused an idempotency key.');
        } catch (\DomainException $exception) {
            self::assertSame('benchmark_idempotency_manifest_conflict', $exception->getMessage());
        }

        $this->assertRejected(fn () => DB::table('estimate_generation_training_examples')->where('id', $exampleId)->update(['work_name' => 'Изменено']));
        $this->assertRejected(fn () => DB::table('estimate_generation_training_examples')->where('id', $exampleId)->update(['reviewed_by' => null, 'reviewed_at' => null]));
        $this->assertRejected(fn () => DB::table('estimate_generation_training_examples')->where('id', $exampleId)->update(['quality_score' => 0.1]));
        $this->assertRejected(fn () => DB::table('estimate_generation_training_examples')->insert([
            'training_dataset_id' => $datasetId, 'organization_id' => $organizationId, 'dataset_version' => 1, 'source_row_hash' => hash('sha256', 'late-row'),
            'work_name' => 'Поздняя работа', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]));
        $this->assertRejected(fn () => DB::table('estimate_generation_training_datasets')->where('id', $datasetId)->update(['title' => 'Изменено']));
        $this->assertRejected(fn () => $this->insertRun($otherOrganizationId, $datasetId, 1, 'cross-org'));
        $this->assertRejected(fn () => $this->insertRun($organizationId, $datasetId, 2, 'wrong-version'));
        $runId = $this->insertRun($organizationId, $datasetId, 1, 'valid');
        DB::table('estimate_generation_benchmark_runs')->where('id', $runId)->update([
            'status' => 'completed', 'metrics' => json_encode(['technical_success_rate' => ['macro' => 1]]), 'case_results' => json_encode([['case_id' => 'contract']]), 'duration_ms' => 10,
            'completed_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertRejected(fn () => DB::table('estimate_generation_benchmark_runs')->where('id', $runId)->update(['metrics' => json_encode([])]));
        $this->assertRejected(fn () => DB::table('estimate_generation_benchmark_runs')->where('id', $runId)->update(['pipeline_version' => 'mutated']));
        $this->assertRejected(fn () => DB::table('estimate_generation_benchmark_runs')->where('id', $runId)->delete());
        DB::table('estimate_generation_training_datasets')->where('id', $datasetId)->update(['status' => 'archived', 'updated_at' => now()]);
        self::assertSame('archived', DB::table('estimate_generation_training_datasets')->where('id', $datasetId)->value('status'));
        $this->assertRejected(fn () => DB::table('estimate_generation_training_datasets')->where('id', $datasetId)->delete());
        $this->assertRejected(fn () => DB::table('organizations')->where('id', $organizationId)->delete());

        $developmentKey = fake()->uuid();
        $developmentId = $this->insertDataset($organizationId, $reviewerId, $developmentKey, 1, 'development');
        self::assertSame(1, (int) DB::table('estimate_generation_training_datasets')->where('id', $developmentId)->value('version'));
        $this->assertRejected(fn () => $this->insertDataset($organizationId, $reviewerId, $developmentKey, 1, 'development'));
        $this->assertRejected(fn () => DB::table('estimate_generation_training_examples')->insert([
            'training_dataset_id' => $developmentId, 'organization_id' => $organizationId, 'dataset_version' => 1, 'source_row_hash' => hash('sha256', 'unreviewed'),
            'work_name' => 'Без проверки', 'status' => 'accepted', 'created_at' => now(), 'updated_at' => now(),
        ]));
        $versionOutputs = $this->runConcurrentWriters('version', $developmentId, $organizationId, 'unused', []);
        $versions = array_map(fn (string $output): int => (int) $this->doneValue($output), $versionOutputs);
        sort($versions);
        self::assertSame([2, 3], $versions);
        self::assertSame([1, 2, 3], DB::table('estimate_generation_training_datasets')->where('organization_id', $organizationId)->where('dataset_key', $developmentKey)->orderBy('version')->pluck('version')->map(fn ($value): int => (int) $value)->all());
        $this->assertRejected(fn () => DB::table('estimate_generation_training_examples')->insert([
            'training_dataset_id' => $developmentId, 'organization_id' => $otherOrganizationId, 'dataset_version' => 1,
            'source_row_hash' => hash('sha256', 'cross-org-membership'), 'work_name' => 'cross org',
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]));

        $queueDatasetId = $this->insertDataset($organizationId, $reviewerId, fake()->uuid(), 1, 'development', 'draft');
        $queueDataset = EstimateGenerationTrainingDataset::query()->findOrFail($queueDatasetId);
        $service = app(EstimateGenerationTrainingDatasetService::class);
        Queue::fake();
        $service->queueProcessing($queueDataset);
        $service->queueProcessing($queueDataset->fresh());
        self::assertSame('draft', DB::table('estimate_generation_training_datasets')->where('id', $queueDatasetId)->value('status'));
        Queue::assertPushed(ProcessEstimateGenerationTrainingDatasetJob::class, 1);
        $job = null;
        Queue::assertPushed(ProcessEstimateGenerationTrainingDatasetJob::class, function (ProcessEstimateGenerationTrainingDatasetJob $queued) use (&$job): bool {
            $job = $queued;

            return true;
        });
        self::assertInstanceOf(ProcessEstimateGenerationTrainingDatasetJob::class, $job);
        try {
            $job->handle($service);
            self::fail('Missing reference file did not reject processing.');
        } catch (\RuntimeException) {
            self::assertSame('rejected', DB::table('estimate_generation_training_datasets')->where('id', $queueDatasetId)->value('status'));
        }
        $job->handle($service);
        self::assertSame('rejected', DB::table('estimate_generation_training_datasets')->where('id', $queueDatasetId)->value('status'));

        $emptyApprovalId = $this->insertDataset($organizationId, $reviewerId, fake()->uuid(), 1, 'development', 'review_required');
        $this->assertRejected(fn () => DB::table('estimate_generation_training_datasets')->where('id', $emptyApprovalId)->update([
            'status' => 'approved', 'approved_by' => $reviewerId, 'approved_at' => now(),
        ]));

        $approvalRaceId = $this->insertDataset($organizationId, $reviewerId, fake()->uuid(), 1, 'development', 'review_required');
        DB::table('estimate_generation_training_examples')->insert([
            'training_dataset_id' => $approvalRaceId, 'organization_id' => $organizationId, 'dataset_version' => 1,
            'source_row_hash' => hash('sha256', 'approval-reviewed'), 'work_name' => 'reviewed', 'status' => 'accepted',
            'reviewed_by' => $reviewerId, 'reviewed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $approvalOutputs = $this->runConcurrentWriters('approval', $approvalRaceId, $organizationId, 'approval', ['reviewer_id' => $reviewerId]);
        self::assertSame(['APPROVED', 'REJECTED'], array_map(fn (string $output): string => $this->doneValue($output), $approvalOutputs));
        self::assertSame(1, DB::table('estimate_generation_training_examples')->where('training_dataset_id', $approvalRaceId)->count());

        $expiredId = $this->insertDataset($organizationId, $reviewerId, fake()->uuid(), 1, 'development', 'draft');
        $expiredToken = fake()->uuid();
        DB::table('estimate_generation_training_datasets')->where('id', $expiredId)->update([
            'status' => 'processing', 'processing_token' => $expiredToken,
            'processing_lease_expires_at' => now()->subMinute(), 'processing_attempt' => 1,
        ]);
        (new RecoverExpiredTrainingDatasetLeasesJob)->handle();
        self::assertSame('draft', DB::table('estimate_generation_training_datasets')->where('id', $expiredId)->value('status'));
        self::assertNull(DB::table('estimate_generation_training_datasets')->where('id', $expiredId)->value('processing_token'));
        Queue::assertPushed(ProcessEstimateGenerationTrainingDatasetJob::class, fn (ProcessEstimateGenerationTrainingDatasetJob $queued): bool => $queued->uniqueId() === (string) $expiredId);

        $dispatchFailureId = $this->insertDataset($organizationId, $reviewerId, fake()->uuid(), 1, 'development', 'draft');
        $dispatchFailureToken = fake()->uuid();
        DB::table('estimate_generation_training_datasets')->where('id', $dispatchFailureId)->update([
            'status' => 'processing', 'processing_token' => $dispatchFailureToken,
            'processing_lease_expires_at' => now()->subMinute(), 'processing_attempt' => 1,
        ]);
        $queueFake = Queue::getFacadeRoot();
        Queue::shouldReceive('connection')->once()->andReturnSelf();
        Queue::shouldReceive('push')->once()->andThrow(new \RuntimeException('dispatch failed'));
        try {
            (new RecoverExpiredTrainingDatasetLeasesJob)->handle();
            self::fail('Dispatch failure was not propagated.');
        } catch (\RuntimeException $exception) {
            self::assertSame('dispatch failed', $exception->getMessage());
        }
        self::assertSame('processing', DB::table('estimate_generation_training_datasets')->where('id', $dispatchFailureId)->value('status'));
        self::assertSame($dispatchFailureToken, (string) DB::table('estimate_generation_training_datasets')->where('id', $dispatchFailureId)->value('processing_token'));
        Queue::swap($queueFake);
        app()->instance(\Illuminate\Contracts\Queue\Factory::class, $queueFake);
        app()->forgetInstance(\Illuminate\Contracts\Bus\Dispatcher::class);
        (new RecoverExpiredTrainingDatasetLeasesJob)->handle();
        self::assertSame('draft', DB::table('estimate_generation_training_datasets')->where('id', $dispatchFailureId)->value('status'));
        Queue::assertPushed(ProcessEstimateGenerationTrainingDatasetJob::class, fn (ProcessEstimateGenerationTrainingDatasetJob $queued): bool => $queued->uniqueId() === (string) $dispatchFailureId);

        try {
            $raceHardening->down();
            self::fail('Forward-only production boundary accepted a destructive rollback.');
        } catch (\RuntimeException $exception) {
            self::assertSame('estimate_generation_training_benchmark_migration_is_forward_only', $exception->getMessage());
        }
        self::assertTrue(\Illuminate\Support\Facades\Schema::hasTable('estimate_generation_benchmark_runs'));
        self::assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('estimate_generation_benchmark_runs', 'case_results_version_scheme'));
    }

    private function insertDataset(int $organizationId, int $reviewerId, string $key, int $version, string $type, string $status = 'approved'): int
    {
        $initialStatus = $status === 'approved' ? 'review_required' : $status;
        $id = DB::table('estimate_generation_training_datasets')->insertGetId([
            'uuid' => fake()->uuid(), 'dataset_key' => $key, 'version' => $version, 'dataset_type' => $type,
            'scope' => 'organization', 'organization_id' => $organizationId, 'title' => 'Contract dataset',
            'source_system' => 'contract', 'status' => $initialStatus, 'quality_status' => 'accepted',
            'source_quality_score' => '1.0000', 'approved_by' => null, 'approved_at' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($status === 'approved') {
            DB::table('estimate_generation_training_examples')->insert([
                'training_dataset_id' => $id, 'organization_id' => $organizationId, 'dataset_version' => $version,
                'source_row_hash' => hash('sha256', "approved-{$id}"), 'work_name' => 'Reviewed example',
                'status' => 'accepted', 'reviewed_by' => $reviewerId, 'reviewed_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('estimate_generation_training_datasets')->where('id', $id)->update([
                'status' => 'approved', 'approved_by' => $reviewerId, 'approved_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $id;
    }

    private function insertRun(int $organizationId, int $datasetId, int $version, string $key): int
    {
        return DB::table('estimate_generation_benchmark_runs')->insertGetId([
            'uuid' => fake()->uuid(), 'idempotency_key' => $key, 'organization_id' => $organizationId,
            'training_dataset_id' => $datasetId, 'dataset_version' => $version, 'pipeline_version' => 'pipeline:v1',
            'model_versions' => '{}', 'normative_version' => 'norm:v1', 'price_version' => 'price:v1',
            'cost_amount' => '0', 'currency' => 'RUB', 'status' => 'running', 'started_at' => now(),
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

    /** @param array<string, mixed> $manifest @return array{string, string} */
    private function runConcurrentWriters(string $mode, int $datasetId, int $organizationId, string $key, array $manifest): array
    {
        $command = [PHP_BINARY, dirname(__DIR__, 3).'/Support/TrainingBenchmarkConcurrentWriter.php', $mode];
        $args = [(string) $datasetId, (string) $organizationId, $key, base64_encode(json_encode($manifest, JSON_THROW_ON_ERROR))];
        $environment = array_replace(getenv(), ['DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => (string) DB::connection()->getDatabaseName()]);
        $leader = proc_open([...$command, 'leader', ...$args], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $leaderPipes, dirname(__DIR__, 4), $environment);
        self::assertIsResource($leader);
        self::assertStringContainsString('LOCKED', $this->waitForProcessToken($leader, $leaderPipes[1], $leaderPipes[2], 'LOCKED'));
        $follower = proc_open([...$command, 'follower', ...$args], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $followerPipes, dirname(__DIR__, 4), $environment);
        self::assertIsResource($follower);
        fwrite($leaderPipes[0], "CONTINUE\n");
        fclose($leaderPipes[0]);
        $leaderOutput = $this->waitForProcessToken($leader, $leaderPipes[1], $leaderPipes[2], 'DONE');
        $followerOutput = $this->waitForProcessToken($follower, $followerPipes[1], $followerPipes[2], 'DONE');
        $leaderError = (string) stream_get_contents($leaderPipes[2]);
        $followerError = (string) stream_get_contents($followerPipes[2]);
        self::assertSame(0, proc_close($leader), $leaderError);
        self::assertSame(0, proc_close($follower), $followerError);

        return [$leaderOutput, $followerOutput];
    }

    private function waitForProcessToken($process, $stdout, $stderr, string $token): string
    {
        stream_set_blocking($stdout, false);
        $output = '';
        $deadline = hrtime(true) + 15_000_000_000;
        do {
            $read = [$stdout];
            $write = null;
            $except = null;
            if (stream_select($read, $write, $except, 1) > 0) {
                $output .= (string) fread($stdout, 8192);
            }
            if (str_contains($output, $token)) {
                return $output;
            }
            if (! proc_get_status($process)['running']) {
                stream_set_blocking($stdout, true);
                $output .= (string) stream_get_contents($stdout);
                if (str_contains($output, $token)) {
                    return $output;
                }
                self::fail(trim((string) stream_get_contents($stderr)) ?: 'Concurrent writer stopped before '.$token.'.');
            }
        } while (hrtime(true) < $deadline);
        self::fail('Concurrent writer timed out before '.$token.'.');
    }

    private function doneValue(string $output): string
    {
        preg_match('/DONE:([^\s]+)/', $output, $matches);

        return $matches[1] ?? '';
    }

    private function requireDisposablePostgres(): void
    {
        $database = (string) DB::connection()->getDatabaseName();
        if (getenv('RUN_POSTGRES_TRAINING_BENCHMARK_CONTRACT') !== '1' || DB::getDriverName() !== 'pgsql' || ! str_ends_with($database, '_contract')) {
            self::markTestSkipped('Requires explicit disposable PostgreSQL training/benchmark contract database.');
        }
    }

    /** @param list<string> $boundaries */
    private function runMigrationWithInterruptions(object $migration, array $boundaries): void
    {
        foreach ($boundaries as $boundary) {
            putenv('ESTIMATE_CONTRACT_INTERRUPT_AFTER='.$boundary);
            try {
                $migration->up();
                self::fail($boundary.' did not interrupt the real migration.');
            } catch (\RuntimeException $exception) {
                self::assertSame('estimate_generation_online_migration_interrupted:'.$boundary, $exception->getMessage());
                $this->assertBoundaryWriteFence($boundary);
            } finally {
                putenv('ESTIMATE_CONTRACT_INTERRUPT_AFTER');
            }
        }
        $migration->up();
        $migration->up();
    }

    private function assertBoundaryWriteFence(string $boundary): void
    {
        if (! in_array($boundary, ['001700_structure', '001800_structure', '002100_structure'], true)) {
            return;
        }
        $organizationId = (int) DB::table('organizations')->insertGetId(['name' => 'Boundary writer']);
        $datasetId = (int) DB::table('estimate_generation_training_datasets')->insertGetId([
            'uuid' => fake()->uuid(), 'organization_id' => $organizationId, 'title' => 'Boundary writer',
            'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($boundary === '001700_structure') {
            self::assertNotNull(DB::table('estimate_generation_training_datasets')->where('id', $datasetId)->value('dataset_key'));
            $exampleId = (int) DB::table('estimate_generation_training_examples')->insertGetId([
                'training_dataset_id' => $datasetId, 'source_row_hash' => hash('sha256', 'review-boundary-'.$datasetId),
                'work_name' => 'Review boundary', 'status' => 'accepted', 'created_at' => now(), 'updated_at' => now(),
            ]);
            self::assertSame('pending', DB::table('estimate_generation_training_examples')->where('id', $exampleId)->value('status'));
            DB::table('estimate_generation_training_examples')->where('id', $exampleId)->delete();
        } elseif ($boundary === '001800_structure') {
            $exampleId = (int) DB::table('estimate_generation_training_examples')->insertGetId([
                'training_dataset_id' => $datasetId, 'source_row_hash' => hash('sha256', 'boundary-'.$datasetId),
                'work_name' => 'Boundary writer', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
            ]);
            self::assertSame($organizationId, (int) DB::table('estimate_generation_training_examples')->where('id', $exampleId)->value('organization_id'));
            DB::table('estimate_generation_training_examples')->where('id', $exampleId)->delete();
        } else {
            DB::table('estimate_generation_training_datasets')->where('id', $datasetId)->update(['status' => 'processing']);
            self::assertNotNull(DB::table('estimate_generation_training_datasets')->where('id', $datasetId)->value('processing_lease_expires_at'));
        }
        DB::table('estimate_generation_training_datasets')->where('id', $datasetId)->delete();
        DB::table('organizations')->where('id', $organizationId)->delete();
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
        if (! $schema->hasColumn('projects', 'organization_id')) {
            $schema->table('projects', static function (\Illuminate\Database\Schema\Blueprint $table): void {
                $table->unsignedBigInteger('organization_id')->nullable();
            });
        }
        if (! $schema->hasColumn('organizations', 'name')) {
            $schema->table('organizations', static function (\Illuminate\Database\Schema\Blueprint $table): void {
                $table->string('name')->nullable();
            });
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
