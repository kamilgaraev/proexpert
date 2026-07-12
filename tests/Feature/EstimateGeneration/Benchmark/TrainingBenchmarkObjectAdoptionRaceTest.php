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
    /** @var list<int> */
    private array $organizationIds = [];

    /** @var list<int> */
    private array $reviewerIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        if (getenv('RUN_POSTGRES_TRAINING_BENCHMARK_CONTRACT') !== '1' || DB::getDriverName() !== 'pgsql') {
            self::markTestSkipped('Requires disposable PostgreSQL contract database.');
        }
        (require dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_12_002200_close_training_benchmark_races.php')->up();
    }

    protected function tearDown(): void
    {
        if (DB::getFacadeRoot() !== null && DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS eg_benchmark_run_immutable ON estimate_generation_benchmark_runs; DROP TRIGGER IF EXISTS eg_approved_dataset_example_guard ON estimate_generation_training_examples; DROP TRIGGER IF EXISTS eg_training_example_immutable ON estimate_generation_training_examples; DROP TRIGGER IF EXISTS eg_training_dataset_immutable ON estimate_generation_training_datasets;');
            DB::table('estimate_generation_benchmark_runs')->whereIn('organization_id', $this->organizationIds)->delete();
            DB::table('estimate_generation_training_examples')->whereIn('organization_id', $this->organizationIds)->delete();
            DB::table('estimate_generation_training_datasets')->whereIn('organization_id', $this->organizationIds)->delete();
            DB::table('organizations')->whereIn('id', $this->organizationIds)->delete();
            DB::table('system_admins')->whereIn('id', $this->reviewerIds)->delete();
            DB::unprepared('CREATE TRIGGER eg_benchmark_run_immutable BEFORE UPDATE OR DELETE ON estimate_generation_benchmark_runs FOR EACH ROW EXECUTE FUNCTION eg_guard_benchmark_run_immutable(); CREATE TRIGGER eg_approved_dataset_example_guard BEFORE INSERT OR UPDATE OR DELETE ON estimate_generation_training_examples FOR EACH ROW EXECUTE FUNCTION eg_guard_example_for_approved_dataset(); CREATE TRIGGER eg_training_example_immutable BEFORE UPDATE OR DELETE ON estimate_generation_training_examples FOR EACH ROW EXECUTE FUNCTION eg_guard_training_example_immutable(); CREATE TRIGGER eg_training_dataset_immutable BEFORE UPDATE OR DELETE ON estimate_generation_training_datasets FOR EACH ROW EXECUTE FUNCTION eg_guard_training_dataset_immutable();');
        }
        \Illuminate\Foundation\Bootstrap\HandleExceptions::flushState($this);
        \Illuminate\Support\Facades\Facade::clearResolvedInstances();
        parent::tearDown();
    }

    public function test_failed_creator_deletes_exact_version_created_after_external_deletion_and_releases_lock(): void
    {
        $organizationId = (int) DB::table('organizations')->insertGetId(['name' => 'Adoption race']);
        $reviewerId = (int) DB::table('system_admins')->insertGetId([]);
        $this->organizationIds[] = $organizationId;
        $this->reviewerIds[] = $reviewerId;
        $datasetId = $this->dataset($organizationId, $reviewerId);
        $uuid = fake()->uuid();
        $this->insertRun($organizationId, $datasetId, $uuid);
        $body = json_encode([['case_id' => 'adoption']], JSON_THROW_ON_ERROR);
        $path = 'org-'.$organizationId.'/estimate-generation/benchmarks/'.$uuid.'/'.hash('sha256', $body).'.json';
        $directory = sys_get_temp_dir().'/most-benchmark-adoption-'.bin2hex(random_bytes(6));
        mkdir($directory);
        $store = new SharedVersionedBenchmarkObjectStore($directory);
        $store->seedObject($path, $body);
        DB::unprepared("CREATE OR REPLACE FUNCTION fail_benchmark_adoption_a() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF current_setting('application_name') = 'benchmark_adoption_fail' AND NEW.status = 'completed' THEN RAISE EXCEPTION 'forced_a_failure'; END IF; RETURN NEW; END $$; CREATE TRIGGER fail_benchmark_adoption_a BEFORE UPDATE ON estimate_generation_benchmark_runs FOR EACH ROW EXECUTE FUNCTION fail_benchmark_adoption_a()");
        try {
            [$a, $aPipes] = $this->open('A', $organizationId, $uuid, $path, $directory);
            $this->waitEvent($directory, 'CREATE ');
            [$lockedOut] = $this->runWorker('LOCK_CHECK', $organizationId, $uuid, $path, $directory);
            self::assertStringContainsString('LOCK_ACQUIRED:no', $lockedOut);
            file_put_contents($directory.'/continue', 'continue');
            [$aOut, $aErr] = $this->finish($a, $aPipes);
            self::assertStringContainsString('FAILED:', $aOut, $aErr);
            [$releasedOut] = $this->runWorker('LOCK_CHECK', $organizationId, $uuid, $path, $directory);
            self::assertStringContainsString('LOCK_ACQUIRED:yes', $releasedOut);
            $events = (string) file_get_contents($directory.'/events.log');
            preg_match('/CREATE '.preg_quote($path, '/').' ([^\s]+)/', $events, $matches);
            $version = $matches[1] ?? '';
            self::assertNotSame('', $version);
            self::assertFalse($store->objectExists($path, $version));
            self::assertSame("HEAD $path source-version\nGET $path source-version\nDELETE_EXTERNAL $path source-version\nCREATE $path $version\nDELETE $path $version\n", $events);
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS fail_benchmark_adoption_a ON estimate_generation_benchmark_runs; DROP FUNCTION IF EXISTS fail_benchmark_adoption_a()');
        }
    }

    public function test_preexisting_object_is_adopted_once_while_waiting_completer_returns_terminal_result(): void
    {
        $organizationId = (int) DB::table('organizations')->insertGetId(['name' => 'Cleanup race']);
        $reviewerId = (int) DB::table('system_admins')->insertGetId([]);
        $this->organizationIds[] = $organizationId;
        $this->reviewerIds[] = $reviewerId;
        $datasetId = $this->dataset($organizationId, $reviewerId);
        $uuid = fake()->uuid();
        $this->insertRun($organizationId, $datasetId, $uuid);
        $body = json_encode([['case_id' => 'cleanup']], JSON_THROW_ON_ERROR);
        $path = 'org-'.$organizationId.'/estimate-generation/benchmarks/'.$uuid.'/'.hash('sha256', $body).'.json';
        $version = 'sha256-'.hash('sha256', $body);
        $directory = sys_get_temp_dir().'/most-benchmark-cleanup-'.bin2hex(random_bytes(6));
        mkdir($directory);
        (new SharedVersionedBenchmarkObjectStore($directory))->seedObject($path, $body, $version);
        [$a, $aPipes] = $this->open('HOLD', $organizationId, $uuid, $path, $directory);
        $this->waitEvent($directory, 'ADOPT ');
        [$lockedOut] = $this->runWorker('LOCK_CHECK', $organizationId, $uuid, $path, $directory);
        self::assertStringContainsString('LOCK_ACQUIRED:no', $lockedOut);
        [$b, $bPipes] = $this->open('B', $organizationId, $uuid, $path, $directory);
        usleep(100_000);
        self::assertTrue(proc_get_status($b)['running']);
        self::assertSame(1, substr_count($this->readEvents($directory), 'ADOPT '));
        file_put_contents($directory.'/continue', 'continue');
        [$aOut, $aErr] = $this->finish($a, $aPipes);
        [$bOut, $bErr] = $this->finish($b, $bPipes);
        self::assertStringContainsString('DONE:completed', $aOut, $aErr);
        self::assertStringContainsString('DONE:completed', $bOut, $bErr);
        [$releasedOut] = $this->runWorker('LOCK_CHECK', $organizationId, $uuid, $path, $directory);
        self::assertStringContainsString('LOCK_ACQUIRED:yes', $releasedOut);
        $events = (string) file_get_contents($directory.'/events.log');
        self::assertSame(1, substr_count($events, 'ADOPT '.$path.' '.$version));
        self::assertStringNotContainsString('CREATE ', $events);
        self::assertStringNotContainsString('DELETE ', $events);
    }

    public function test_adopted_preexisting_referenced_version_is_never_deleted(): void
    {
        $organizationId = (int) DB::table('organizations')->insertGetId(['name' => 'Referenced adoption']);
        $reviewerId = (int) DB::table('system_admins')->insertGetId([]);
        $this->organizationIds[] = $organizationId;
        $this->reviewerIds[] = $reviewerId;
        $datasetId = $this->dataset($organizationId, $reviewerId);
        $uuid = fake()->uuid();
        $this->insertRun($organizationId, $datasetId, $uuid);
        $body = json_encode([['case_id' => 'referenced']], JSON_THROW_ON_ERROR);
        $path = 'org-'.$organizationId.'/estimate-generation/benchmarks/'.$uuid.'/'.hash('sha256', $body).'.json';
        $version = 'sha256-'.hash('sha256', $body);
        $directory = sys_get_temp_dir().'/most-benchmark-referenced-'.bin2hex(random_bytes(6));
        mkdir($directory);
        $store = new SharedVersionedBenchmarkObjectStore($directory);
        $store->seedObject($path, $body, $version);
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

    private function runWorker(string $role, int $org, string $uuid, string $path, string $dir): array
    {
        [$process, $pipes] = $this->open($role, $org, $uuid, $path, $dir);

        return $this->finish($process, $pipes);
    }

    private function waitEvent(string $dir, string $token): void
    {
        $deadline = microtime(true) + 10;
        do {
            if (str_contains($this->readEvents($dir), $token)) {
                return;
            } usleep(10000);
        } while (microtime(true) < $deadline);
        self::fail('Object event timeout.');
    }

    private function readEvents(string $dir): string
    {
        $deadline = microtime(true) + 2;
        do {
            $events = @file_get_contents($dir.'/events.log');
            if (is_string($events)) {
                return $events;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);

        self::fail('Object event log unavailable.');
    }
}
