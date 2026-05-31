<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateDatasetVersion;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeCandidateSearchService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class NormativeCandidateSearchServiceTest extends TestCase
{
    public function test_search_filters_to_compatible_unit_candidates_when_available(): void
    {
        $versionId = (int) DB::table('estimate_dataset_versions')->insertGetId([
            'source_type' => 'fsnb_2022',
            'version_key' => '2026-05-10',
            'bucket' => 'test-bucket',
            'prefix' => 'test-prefix',
            'status' => 'parsed',
            'files_count' => 1,
            'rows_read' => 1,
            'rows_imported' => 1,
            'errors_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $collectionId = (int) DB::table('estimate_norm_collections')->insertGetId([
            'dataset_version_id' => $versionId,
            'code' => 'gesnm',
            'name' => 'ГЭСНм',
            'norm_type' => 'gesnm',
            'source_file' => 'ГЭСНм.xml',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $sectionId = (int) DB::table('estimate_norm_sections')->insertGetId([
            'collection_id' => $collectionId,
            'code' => '08',
            'name' => 'Электромонтажные работы',
            'section_type' => 'Сборник',
            'depth' => 0,
            'path' => '08',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('estimate_norms')->insert([
            [
                'collection_id' => $collectionId,
                'section_id' => $sectionId,
                'code' => '08-01-025-01',
                'name' => 'Прокладка кабельных линий для блочных подстанций',
                'unit' => 'шт',
                'section_code' => '08-01-025',
                'section_name' => 'Электромонтажные работы',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'collection_id' => $collectionId,
                'section_id' => $sectionId,
                'code' => '08-02-147-01',
                'name' => 'Прокладка кабеля в готовых каналах',
                'unit' => 'м',
                'section_code' => '08-02-147',
                'section_name' => 'Электромонтажные работы',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $candidates = app(NormativeCandidateSearchService::class)->search(
            EstimateDatasetVersion::query()->findOrFail($versionId),
            [
                'name' => 'Прокладка кабельных линий',
                'unit' => 'м',
            ],
            [
                'scope_type' => 'engineering',
                'section_title' => 'Электроснабжение',
            ],
            ['прокладка', 'кабельных', 'линий'],
            10
        );

        $this->assertSame(['08-02-147-01'], $candidates->pluck('code')->values()->all());
    }

    public function test_search_excludes_forbidden_domain_candidates_before_returning_pool(): void
    {
        $versionId = (int) DB::table('estimate_dataset_versions')->insertGetId([
            'source_type' => 'fsnb_2022',
            'version_key' => '2026-05-31',
            'bucket' => 'test-bucket',
            'prefix' => 'test-prefix',
            'status' => 'parsed',
            'files_count' => 1,
            'rows_read' => 1,
            'rows_imported' => 1,
            'errors_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $collectionId = (int) DB::table('estimate_norm_collections')->insertGetId([
            'dataset_version_id' => $versionId,
            'code' => 'gesn',
            'name' => 'ГЭСН',
            'norm_type' => 'gesn',
            'source_file' => 'ГЭСН.xml',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $sectionId = (int) DB::table('estimate_norm_sections')->insertGetId([
            'collection_id' => $collectionId,
            'code' => '08',
            'name' => 'Конструкции из кирпича и блоков',
            'section_type' => 'Сборник',
            'depth' => 0,
            'path' => '08',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('estimate_norms')->insert([
            [
                'collection_id' => $collectionId,
                'section_id' => $sectionId,
                'code' => '08-01-001-01',
                'name' => 'Кладка наружных стен из газобетона в составе шпунтового ограждения',
                'unit' => 'м3',
                'section_code' => '08-01-001',
                'section_name' => 'Конструкции из кирпича и блоков',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'collection_id' => $collectionId,
                'section_id' => $sectionId,
                'code' => '08-02-001-01',
                'name' => 'Кладка наружных стен из газобетонных блоков',
                'unit' => 'м3',
                'section_code' => '08-02-001',
                'section_name' => 'Конструкции из кирпича и блоков',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $candidates = app(NormativeCandidateSearchService::class)->search(
            EstimateDatasetVersion::query()->findOrFail($versionId),
            [
                'name' => 'Кладка наружных стен из газобетона D500 400 мм',
                'unit' => 'м3',
            ],
            [
                'scope_type' => 'walls',
                'section_title' => 'Наружные стены',
            ],
            ['кладка', 'наружных', 'стен', 'газобетона'],
            10
        );

        $this->assertSame(['08-02-001-01'], $candidates->pluck('code')->values()->all());
    }
}
