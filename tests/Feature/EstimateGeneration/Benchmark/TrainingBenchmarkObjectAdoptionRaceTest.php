<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Benchmark;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\SharedVersionedBenchmarkObjectStore;

#[Group('postgres-contract')]
final class TrainingBenchmarkObjectAdoptionRaceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        if (getenv('RUN_POSTGRES_TRAINING_BENCHMARK_CONTRACT') !== '1' || DB::getDriverName() !== 'pgsql') {
            self::markTestSkipped('Requires disposable PostgreSQL contract database.');
        }
    }

    protected function tearDown(): void
    {
        \Illuminate\Foundation\Bootstrap\HandleExceptions::flushState($this);
        \Illuminate\Support\Facades\Facade::clearResolvedInstances();
        parent::tearDown();
    }

    public function test_failed_creator_does_not_delete_version_adopted_by_waiting_process(): void
    {
        $organizationId = (int) DB::table('organizations')->insertGetId(['name' => 'Adoption race']);
        $reviewerId = (int) DB::table('system_admins')->insertGetId([]);
        $datasetId = $this->dataset($organizationId, $reviewerId);
        $uuid = fake()->uuid();
        $this->insertRun($organizationId, $datasetId, $uuid);
        $body = json_encode([['case_id' => 'adoption']], JSON_THROW_ON_ERROR);
        $path = 'org-'.$organizationId.'/estimate-generation/benchmarks/'.$uuid.'/'.hash('sha256', $body).'.json';
        $directory = sys_get_temp_dir().'/most-benchmark-adoption-'.bin2hex(random_bytes(6));
        mkdir($directory);
        file_put_contents($directory.'/source.json', $body);
        DB::unprepared("CREATE OR REPLACE FUNCTION fail_benchmark_adoption_a() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF current_setting('application_name') = 'benchmark_adoption_fail' AND NEW.status = 'completed' THEN RAISE EXCEPTION 'forced_a_failure'; END IF; RETURN NEW; END $$; CREATE TRIGGER fail_benchmark_adoption_a BEFORE UPDATE ON estimate_generation_benchmark_runs FOR EACH ROW EXECUTE FUNCTION fail_benchmark_adoption_a()");
        try {
            [$a, $aPipes] = $this->open('A', $organizationId, $uuid, $path, $directory);
            $this->waitEvent($directory, 'CREATE ');
            [$b, $bPipes] = $this->open('B', $organizationId, $uuid, $path, $directory);
            usleep(100_000);
            self::assertTrue(proc_get_status($b)['running']);
            self::assertSame(1, substr_count((string) file_get_contents($directory.'/events.log'), 'CREATE '));
            file_put_contents($directory.'/continue', 'continue');
            [$aOut, $aErr] = $this->finish($a, $aPipes);
            [$bOut, $bErr] = $this->finish($b, $bPipes);
            self::assertStringContainsString('FAILED:', $aOut, $aErr);
            self::assertStringContainsString('DONE:completed', $bOut, $bErr);
            self::assertStringContainsString('LOCK_RELEASED:yes', $aOut.$bOut);
            $row = DB::table('estimate_generation_benchmark_runs')->where('uuid', $uuid)->first();
            $version = 'sha256-'.hash('sha256', $body);
            self::assertSame($version, $row->case_results_version);
            self::assertTrue((new SharedVersionedBenchmarkObjectStore($directory))->objectExists($path, $version));
            $events = file_get_contents($directory.'/events.log');
            self::assertSame(2, substr_count($events, 'CREATE '.$path.' '.$version));
            self::assertStringContainsString('DELETE '.$path.' '.$version, $events);
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS fail_benchmark_adoption_a ON estimate_generation_benchmark_runs; DROP FUNCTION IF EXISTS fail_benchmark_adoption_a()');
        }
    }

    public function test_failed_creator_deletes_only_its_unreferenced_exact_version(): void
    {
        $organizationId = (int) DB::table('organizations')->insertGetId(['name' => 'Cleanup race']);
        $reviewerId = (int) DB::table('system_admins')->insertGetId([]);
        $datasetId = $this->dataset($organizationId, $reviewerId);
        $uuid = fake()->uuid();
        $this->insertRun($organizationId, $datasetId, $uuid);
        $body = json_encode([['case_id' => 'cleanup']], JSON_THROW_ON_ERROR);
        $path = 'org-'.$organizationId.'/estimate-generation/benchmarks/'.$uuid.'/'.hash('sha256', $body).'.json';
        $version = 'sha256-'.hash('sha256', $body);
        $directory = sys_get_temp_dir().'/most-benchmark-cleanup-'.bin2hex(random_bytes(6));
        mkdir($directory);
        file_put_contents($directory.'/source.json', $body);
        DB::unprepared("CREATE OR REPLACE FUNCTION fail_benchmark_adoption_a() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF current_setting('application_name') = 'benchmark_adoption_fail' AND NEW.status = 'completed' THEN RAISE EXCEPTION 'forced_a_failure'; END IF; RETURN NEW; END $$; CREATE TRIGGER fail_benchmark_adoption_a BEFORE UPDATE ON estimate_generation_benchmark_runs FOR EACH ROW EXECUTE FUNCTION fail_benchmark_adoption_a()");
        try {
            [$a, $pipes] = $this->open('A', $organizationId, $uuid, $path, $directory);
            $this->waitEvent($directory, 'CREATE ');
            file_put_contents($directory.'/continue', 'continue');
            [$output, $error] = $this->finish($a, $pipes);
            self::assertStringContainsString('FAILED:', $output, $error);
            self::assertStringContainsString('LOCK_RELEASED:yes', $output);
            self::assertFalse((new SharedVersionedBenchmarkObjectStore($directory))->objectExists($path, $version));
            self::assertStringContainsString('DELETE '.$path.' '.$version, (string) file_get_contents($directory.'/events.log'));
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS fail_benchmark_adoption_a ON estimate_generation_benchmark_runs; DROP FUNCTION IF EXISTS fail_benchmark_adoption_a()');
        }
    }

    public function test_adopted_preexisting_referenced_version_is_never_deleted(): void
    {
        $organizationId = (int) DB::table('organizations')->insertGetId(['name' => 'Referenced adoption']);
        $reviewerId = (int) DB::table('system_admins')->insertGetId([]);
        $datasetId = $this->dataset($organizationId, $reviewerId);
        $uuid = fake()->uuid();
        $this->insertRun($organizationId, $datasetId, $uuid);
        $body = json_encode([['case_id' => 'referenced']], JSON_THROW_ON_ERROR);
        $path = 'org-'.$organizationId.'/estimate-generation/benchmarks/'.$uuid.'/'.hash('sha256', $body).'.json';
        $version = 'sha256-'.hash('sha256', $body);
        $directory = sys_get_temp_dir().'/most-benchmark-referenced-'.bin2hex(random_bytes(6));
        mkdir($directory);
        file_put_contents($directory.'/source.json', $body);
        $store = new SharedVersionedBenchmarkObjectStore($directory);
        $stored = $store->putImmutable($path, $body, 'application/json');
        self::assertTrue($stored->created);
        DB::table('estimate_generation_benchmark_runs')->where('uuid', $uuid)->update([
            'status' => 'completed', 'metrics' => json_encode(['technical_success_rate' => ['macro' => 1]]), 'case_results_storage_disk' => 's3',
            'case_results_storage_path' => $path, 'case_results_size' => strlen($body), 'case_results_sha256' => hash('sha256', $body),
            'case_results_etag' => 'fake-etag', 'case_results_version' => $version, 'case_results_version_scheme' => 'provider+sha256',
            'case_results_content_type' => 'application/json', 'duration_ms' => 10, 'completed_at' => now(), 'updated_at' => now(),
        ]);
        $adopted = $store->putImmutable($path, $body, 'application/json');
        self::assertFalse($adopted->created);
        $store->removeCreated($adopted);
        self::assertTrue($store->objectExists($path, $version));
        $events = (string) file_get_contents($directory.'/events.log');
        self::assertStringContainsString('ADOPT '.$path.' '.$version, $events);
        self::assertStringNotContainsString('DELETE ', $events);
        self::assertSame(1, DB::table('estimate_generation_benchmark_runs')->where('case_results_storage_path', $path)->where('case_results_version', $version)->where('status', 'completed')->count());
    }

    private function dataset(int $org, int $reviewer): int
    {
        $id = DB::table('estimate_generation_training_datasets')->insertGetId(['uuid' => fake()->uuid(), 'dataset_key' => fake()->uuid(), 'version' => 1, 'dataset_type' => 'acceptance', 'scope' => 'organization', 'organization_id' => $org, 'title' => 'Race', 'source_system' => 'test', 'status' => 'review_required', 'quality_status' => 'accepted', 'source_quality_score' => '1', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('estimate_generation_training_examples')->insert(['training_dataset_id' => $id, 'organization_id' => $org, 'dataset_version' => 1, 'source_row_hash' => hash('sha256', 'race'), 'work_name' => 'race', 'status' => 'accepted', 'reviewed_by' => $reviewer, 'reviewed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('estimate_generation_training_datasets')->where('id', $id)->update(['status' => 'approved', 'approved_by' => $reviewer, 'approved_at' => now()]);

        return $id;
    }

    private function insertRun(int $org, int $dataset, string $uuid): void
    {
        DB::table('estimate_generation_benchmark_runs')->insert(['uuid' => $uuid, 'idempotency_key' => fake()->uuid(), 'organization_id' => $org, 'training_dataset_id' => $dataset, 'dataset_version' => 1, 'pipeline_version' => 'v1', 'model_versions' => '{}', 'normative_version' => 'v1', 'price_version' => 'v1', 'cost_amount' => '0', 'currency' => 'RUB', 'status' => 'running', 'started_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function open(string $role, int $org, string $uuid, string $path, string $dir): array
    {
        $env = array_replace(getenv(), ['DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => (string) DB::connection()->getDatabaseName()]);
        $p = proc_open([PHP_BINARY, dirname(__DIR__, 3).'/Support/TrainingBenchmarkAdoptionWriter.php', $role, (string) $org, $uuid, $path, $dir], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, dirname(__DIR__, 4), $env);
        self::assertIsResource($p);
        fclose($pipes[0]);

        return [$p, $pipes];
    }

    private function finish($process, array $pipes): array
    {
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $err);

        return [$out, $err];
    }

    private function waitEvent(string $dir, string $token): void
    {
        $deadline = microtime(true) + 10;
        do {
            if (is_file($dir.'/events.log') && str_contains((string) file_get_contents($dir.'/events.log'), $token)) {
                return;
            } usleep(10000);
        } while (microtime(true) < $deadline);
        self::fail('Object event timeout.');
    }
}
