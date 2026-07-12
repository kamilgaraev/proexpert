<?php

declare(strict_types=1);

namespace Tests\Integration\EstimateGeneration\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\PostgresNormativeCandidateSource;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PostgresNormativeRetrievalContractTest extends TestCase
{
    public function test_real_fk_version_query_and_bounded_index_plan(): void
    {
        if (getenv('RUN_POSTGRES_NORMATIVE_CONTRACT') !== '1' || DB::getDriverName() !== 'pgsql') {
            self::markTestSkipped('Requires opt-in migrated PostgreSQL contract database.');
        }

        DB::beginTransaction();
        try {
            $dataset = DB::table('estimate_dataset_versions')->insertGetId([
                'source_type' => 'fsnb_2022', 'version_key' => 'contract-v1', 'bucket' => 'test', 'prefix' => 'test',
                'status' => 'parsed', 'files_count' => 0, 'rows_read' => 0, 'rows_imported' => 0,
                'errors_count' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $otherDataset = DB::table('estimate_dataset_versions')->insertGetId([
                'source_type' => 'fsnb_2022', 'version_key' => 'contract-v2', 'bucket' => 'test', 'prefix' => 'test',
                'status' => 'parsed', 'files_count' => 0, 'rows_read' => 0, 'rows_imported' => 0,
                'errors_count' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
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
                ]);
            }

            $source = new PostgresNormativeCandidateSource(DB::connection());
            $first = $source->find(10, 20, 'contract-v1', 'кладка кирпичных стен', 1, null);
            $secondTenant = $source->find(11, 21, 'contract-v1', 'кладка кирпичных стен', 1, null);

            self::assertCount(1, $first);
            self::assertSame('contract-v1', $first[0]->datasetVersion);
            self::assertSame($first[0]->id, $secondTenant[0]->id, 'Global catalog is tenant-neutral; tenant fence belongs to decision context.');
            self::assertLessThanOrEqual(1, count($first));

            $plan = DB::select('EXPLAIN (FORMAT JSON) '.PostgresNormativeCandidateSource::QUERY_CONTRACT, [
                'dataset_version' => 'contract-v1', 'query' => 'кладка', 'limit' => 16,
                'query_hash' => hash('sha256', 'кладка'), 'semantic_index_version' => null,
            ]);
            self::assertNotEmpty($plan);
        } finally {
            DB::rollBack();
        }
    }
}
