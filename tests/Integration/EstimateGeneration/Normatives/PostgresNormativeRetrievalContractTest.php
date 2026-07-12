<?php

declare(strict_types=1);

namespace Tests\Integration\EstimateGeneration\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NoopNormativeRolloutFaultInjector;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeRetrievalBackfillService;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeRetrievalRolloutService;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeRolloutFaultInjector;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\PostgresNormativeCandidateSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class PostgresNormativeRetrievalContractTest extends TestCase
{
    public function test_moving_high_water_future_writes_branch_plans_and_resumable_deploy(): void
    {
        $this->requireDisposableContractDatabase();
        $suffix = Str::lower(Str::random(12));
        $version = 'contract-'.$suffix;
        $datasetIds = [];

        try {
            $dataset = $this->dataset($version, $suffix);
            $datasetIds[] = $dataset;
            $collection = $this->collection($dataset, $suffix);
            $initial = $this->norm($collection, '08-01-001-01', 'Кладка кирпичных стен');
            $this->norm($collection, '08-01-001-02', 'Кладка внутренних кирпичных стен');
            $backfill = new NormativeRetrievalBackfillService(DB::connection());
            $first = $backfill->resume(1);
            $initialTarget = $this->targetMaxId();
            $higher = $this->norm($collection, '08-01-001-03', 'Кладка наружных кирпичных стен');
            $second = $backfill->resume(1);

            self::assertGreaterThan($initialTarget, $this->targetMaxId());
            self::assertGreaterThanOrEqual($higher, $this->targetMaxId());
            self::assertFalse($first['complete']);
            while (($second['complete'] ?? false) !== true) {
                $second = $backfill->resume(1000);
            }

            self::assertSame(0, DB::connection()->transactionLevel());
            foreach (['index_collection', 'constraint_validity', 'validate'] as $faultPhase) {
                $this->assertFaultResume($faultPhase);
            }
            $state = (new NormativeRetrievalRolloutService(DB::connection(), new NoopNormativeRolloutFaultInjector))->deploy();
            self::assertSame('enabled', $state['deploy_status']);

            $this->semanticScore($initial);
            $future = $this->norm($collection, '08-01-001-04', 'Кладка будущей стены');
            $futureRow = DB::table('estimate_norms')->where('id', $future)->first(['canonical_unit', 'search_vector']);
            self::assertSame('м2', $futureRow->canonical_unit);
            self::assertNotNull($futureRow->search_vector);
            $found = (new PostgresNormativeCandidateSource(DB::connection()))
                ->find(10, 20, $version, 'Кладка будущей стены', 16, null);
            self::assertContains((string) $future, array_map(static fn ($candidate): string => $candidate->id, $found));

            DB::statement('SET enable_seqscan = off');
            $plan = DB::select(
                'EXPLAIN (FORMAT JSON) '.PostgresNormativeCandidateSource::QUERY_CONTRACT,
                $this->queryBindings($version),
            );
            $encoded = json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            self::assertStringContainsString('estimate_norms_search_vector_gin', $encoded);
            self::assertStringContainsString('estimate_norm_semantic_lookup_idx', $encoded);
        } finally {
            DB::statement('RESET enable_seqscan');
            DB::table('estimate_dataset_versions')->whereIn('id', $datasetIds)->delete();
        }
    }

    private function assertFaultResume(string $faultPhase): void
    {
        DB::table('estimate_normative_retrieval_rollouts')
            ->where('schema_version', NormativeRetrievalBackfillService::VERSION)
            ->update(['backfill_status' => 'complete', 'deploy_phase' => 'pending', 'deploy_status' => 'pending']);
        $fault = new class($faultPhase) implements NormativeRolloutFaultInjector
        {
            public function __construct(private string $target) {}

            public function after(string $phase): void
            {
                if ($phase === $this->target) {
                    throw new RuntimeException('injected');
                }
            }
        };

        try {
            (new NormativeRetrievalRolloutService(DB::connection(), $fault))->deploy();
            self::fail('Fault was not injected.');
        } catch (RuntimeException $exception) {
            self::assertSame('injected', $exception->getMessage());
        }

        $failed = (array) DB::table('estimate_normative_retrieval_rollouts')
            ->where('schema_version', NormativeRetrievalBackfillService::VERSION)
            ->first();
        self::assertSame('complete', $failed['backfill_status']);
        self::assertSame('failed', $failed['deploy_status']);
        self::assertSame($this->expectedDeployPhase($faultPhase), $failed['deploy_phase']);
        $resumed = (new NormativeRetrievalRolloutService(DB::connection(), new NoopNormativeRolloutFaultInjector))->deploy();
        self::assertSame('enabled', $resumed['deploy_status']);
        self::assertTrue(DB::table('pg_indexes')->where('indexname', 'estimate_norms_search_vector_gin')->exists());
        self::assertTrue(DB::table('pg_constraint')->where('conname', 'estimate_norms_validity_ck')->exists());
    }

    private function requireDisposableContractDatabase(): void
    {
        $database = (string) DB::connection()->getDatabaseName();
        $allowed = str_ends_with($database, '_contract') || getenv('ALLOW_DESTRUCTIVE_CONTRACT_DB') === '1';
        if (getenv('RUN_POSTGRES_NORMATIVE_CONTRACT') !== '1' || DB::getDriverName() !== 'pgsql' || ! $allowed) {
            self::markTestSkipped('Requires explicit disposable PostgreSQL _contract database.');
        }
    }

    private function targetMaxId(): int
    {
        return (int) DB::table('estimate_normative_retrieval_rollouts')
            ->where('schema_version', NormativeRetrievalBackfillService::VERSION)
            ->value('target_max_id');
    }

    private function dataset(string $version, string $suffix): int
    {
        return (int) DB::table('estimate_dataset_versions')->insertGetId([
            'source_type' => 'fsnb_2022', 'version_key' => $version, 'bucket' => 'contract',
            'prefix' => $suffix, 'status' => 'parsed', 'files_count' => 0, 'rows_read' => 0,
            'rows_imported' => 0, 'errors_count' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function collection(int $dataset, string $suffix): int
    {
        return (int) DB::table('estimate_norm_collections')->insertGetId([
            'dataset_version_id' => $dataset, 'code' => '08-'.$suffix, 'name' => 'Каменные конструкции',
            'norm_type' => 'gesn', 'source_file' => $suffix.'.xml', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function norm(int $collection, string $code, string $name): int
    {
        return (int) DB::table('estimate_norms')->insertGetId([
            'collection_id' => $collection, 'code' => $code, 'name' => $name, 'unit' => 'м2',
            'material' => 'кирпич', 'technology' => 'кладка', 'structure' => 'стена',
            'object_type' => 'жилой', 'section_code' => '08', 'valid_from' => '2025-01-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function semanticScore(int $normId): void
    {
        DB::table('estimate_norm_semantic_scores')->insert([
            'estimate_norm_id' => $normId,
            'query_hash' => hash('sha256', 'кладка'), 'index_version' => 'contract-sem-v1',
            'score' => 0.95, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function queryBindings(string $version): array
    {
        return [
            'lexical_dataset_version' => $version, 'semantic_dataset_version' => $version,
            'query' => 'кладка', 'query_hash' => hash('sha256', 'кладка'),
            'semantic_index_version' => 'contract-sem-v1', 'lexical_limit' => 16, 'semantic_limit' => 16,
        ];
    }

    private function expectedDeployPhase(string $faultPhase): string
    {
        return match ($faultPhase) {
            'index_collection' => 'indexes',
            'constraint_validity' => 'constraints',
            'validate' => 'validate',
        };
    }
}
