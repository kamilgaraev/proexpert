<?php

declare(strict_types=1);

namespace Tests\Integration\EstimateGeneration\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NoopNormativeRolloutFaultInjector;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeRetrievalBackfillService;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeRetrievalRolloutService;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\PostgresNormativeCandidateSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PostgresNormativeRetrievalContractTest extends TestCase
{
    public function test_real_fk_version_query_and_bounded_index_plan(): void
    {
        if (getenv('RUN_POSTGRES_NORMATIVE_CONTRACT') !== '1' || DB::getDriverName() !== 'pgsql') {
            self::markTestSkipped('Requires opt-in migrated PostgreSQL contract database.');
        }

        $suffix = Str::lower(Str::random(12));
        $versionOne = 'contract-'.$suffix.'-v1';
        $versionTwo = 'contract-'.$suffix.'-v2';
        $datasetIds = [];
        try {
            $dataset = DB::table('estimate_dataset_versions')->insertGetId([
                'source_type' => 'fsnb_2022', 'version_key' => $versionOne, 'bucket' => 'test', 'prefix' => $suffix,
                'status' => 'parsed', 'files_count' => 0, 'rows_read' => 0, 'rows_imported' => 0,
                'errors_count' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $otherDataset = DB::table('estimate_dataset_versions')->insertGetId([
                'source_type' => 'fsnb_2022', 'version_key' => $versionTwo, 'bucket' => 'test', 'prefix' => $suffix,
                'status' => 'parsed', 'files_count' => 0, 'rows_read' => 0, 'rows_imported' => 0,
                'errors_count' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $datasetIds = [$dataset, $otherDataset];
            foreach ([$dataset, $otherDataset] as $id) {
                $collection = DB::table('estimate_norm_collections')->insertGetId([
                    'dataset_version_id' => $id, 'code' => '08', 'name' => 'Каменные конструкции',
                    'norm_type' => 'gesn', 'source_file' => 'fixture.xml', 'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('estimate_norms')->insert([
                    'collection_id' => $collection, 'code' => '08-01-001-01', 'name' => 'Кладка кирпичных стен',
                    'unit' => 'м2', 'canonical_unit' => 'м2', 'unit_dimension' => 'area', 'material' => 'кирпич',
                    'technology' => 'кладка', 'structure' => 'стена', 'object_type' => 'жилой',
                    'section_code' => '08', 'valid_from' => '2025-01-01', 'created_at' => now(), 'updated_at' => now(),
                    'raw_payload' => json_encode(['valid_to' => '2026-99-99'], JSON_THROW_ON_ERROR),
                ]);
            }

            $backfill = new NormativeRetrievalBackfillService(DB::connection());
            $firstBatch = $backfill->resume(1);
            $secondBatch = $backfill->resume(1);
            self::assertSame(1, $firstBatch['processed']);
            self::assertGreaterThan($firstBatch['next_cursor'], $secondBatch['next_cursor']);
            self::assertNull(DB::table('estimate_norms')->orderBy('id')->value('valid_to'));
            while (($secondBatch['complete'] ?? false) !== true) {
                $secondBatch = $backfill->resume(1000);
            }
            self::assertSame(0, DB::connection()->transactionLevel());
            (new NormativeRetrievalRolloutService(DB::connection(), new NoopNormativeRolloutFaultInjector))->deploy();

            $source = new PostgresNormativeCandidateSource(DB::connection());
            $first = $source->find(10, 20, $versionOne, 'кладка кирпичных стен', 1, null);
            $secondTenant = $source->find(11, 21, $versionOne, 'кладка кирпичных стен', 1, null);

            self::assertCount(1, $first);
            self::assertSame($versionOne, $first[0]->datasetVersion);
            self::assertSame($first[0]->id, $secondTenant[0]->id, 'Global catalog is tenant-neutral; tenant fence belongs to decision context.');
            self::assertLessThanOrEqual(1, count($first));

            DB::statement('SET enable_seqscan = off');
            $plan = DB::select('EXPLAIN (FORMAT JSON) '.PostgresNormativeCandidateSource::QUERY_CONTRACT, [
                'lexical_dataset_version' => $versionOne, 'semantic_dataset_version' => $versionOne, 'query' => 'кладка',
                'query_hash' => hash('sha256', 'кладка'), 'semantic_index_version' => null,
                'lexical_limit' => 16, 'semantic_limit' => 16,
            ]);
            self::assertNotEmpty($plan);
            $encodedPlan = json_encode($plan, JSON_THROW_ON_ERROR);
            self::assertStringContainsString('estimate_norms_search_vector_gin', $encodedPlan);
            self::assertStringNotContainsString('Seq Scan', $encodedPlan);
        } finally {
            DB::statement('RESET enable_seqscan');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS estimate_norms_search_vector_gin');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS estimate_norms_section_dimension_idx');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS estimate_norms_collection_unit_idx');
            DB::table('estimate_dataset_versions')->whereIn('id', $datasetIds)->delete();
            DB::table('estimate_normative_retrieval_rollouts')->where('schema_version', NormativeRetrievalBackfillService::VERSION)->delete();
        }
    }
}
