<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateDatasetVersion;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeCandidateSearchService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

#[Group('postgres-contract')]
final class NormativeCandidateSearchServiceTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_search_ranks_intent_matches_before_limiting_a_large_pool_without_crossing_dataset_versions(): void
    {
        $collections = [];
        foreach (['2026-06-10', '2026-06-11'] as $versionKey) {
            $versionId = (int) DB::table('estimate_dataset_versions')->insertGetId([
                'source_type' => 'fsnb_2022',
                'version_key' => $versionKey,
                'bucket' => 'test-bucket',
                'prefix' => 'test-prefix',
                'status' => 'parsed',
                'files_count' => 1,
                'rows_read' => 131,
                'rows_imported' => 131,
                'errors_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $collections[$versionId] = (int) DB::table('estimate_norm_collections')->insertGetId([
                'dataset_version_id' => $versionId,
                'code' => 'gesn',
                'name' => 'ГЭСН',
                'norm_type' => 'gesn',
                'source_file' => 'ГЭСН.xml',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $versionIds = array_keys($collections);
        $collectionId = $collections[$versionIds[0]];
        $norm = [
            'collection_id' => $collectionId,
            'unit' => 'м3',
            'section_code' => '01',
            'section_name' => 'Земляные работы',
            'work_composition' => json_encode(['Подготовительные работы с бетоном'], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ];
        $decoys = [];
        for ($index = 0; $index < 130; $index++) {
            $decoys[] = [...$norm, 'code' => sprintf('01-01-%03d-01', $index + 1), 'name' => 'Устройство бетонной подготовки'];
        }
        DB::table('estimate_norms')->insert($decoys);
        $target = [
            ...$norm,
            'unit' => '100 м³',
            'code' => '06-01-005-05',
            'name' => 'Устройство железобетонных фундаментов общего назначения объемом более 5 до 25 м3',
            'section_code' => '06',
            'section_name' => 'Бетонные и железобетонные конструкции',
            'work_composition' => json_encode(['Укладка бетонной смеси в фундаменты'], JSON_UNESCAPED_UNICODE),
        ];
        $targetId = (int) DB::table('estimate_norms')->insertGetId($target);
        $otherVersionId = (int) DB::table('estimate_norms')->insertGetId([
            ...$target,
            'collection_id' => $collections[$versionIds[1]],
        ]);
        $candidates = app(NormativeCandidateSearchService::class)->search(
            EstimateDatasetVersion::query()->findOrFail($versionIds[0]),
            ['name' => 'Бетонирование фундаментов', 'unit' => 'м3'],
            ['scope_type' => 'foundation', 'section_title' => 'Фундамент'],
            ['бетон', 'фундамент'],
            20,
            80,
        );

        self::assertSame($targetId, (int) $candidates->first()?->id);
        self::assertCount(20, $candidates);
        self::assertNotContains($otherVersionId, $candidates->pluck('id')->all());

        $wrongUnits = [];
        for ($index = 0; $index < 130; $index++) {
            $wrongUnits[] = [
                ...$norm,
                'code' => sprintf('06-02-%03d-01', $index + 1),
                'name' => 'Бетонирование монолитных фундаментов: бетон, арматура, опалубка, грунт',
                'section_code' => '06',
                'unit' => 'шт',
            ];
        }
        DB::table('estimate_norms')->insert($wrongUnits);
        $withWrongUnits = app(NormativeCandidateSearchService::class)->search(
            EstimateDatasetVersion::query()->findOrFail($versionIds[0]),
            ['name' => 'Бетонирование фундаментов', 'unit' => 'м3'],
            ['scope_type' => 'foundation', 'section_title' => 'Фундамент'],
            ['бетон', 'фундамент'],
            20,
            80,
        );
        self::assertSame($targetId, (int) $withWrongUnits->first()?->id);
        self::assertNotContains('шт', $withWrongUnits->pluck('unit')->all());

        $byCode = app(NormativeCandidateSearchService::class)->search(
            EstimateDatasetVersion::query()->findOrFail($versionIds[0]),
            ['name' => 'Бетонирование фундаментов', 'unit' => 'м3'],
            ['scope_type' => 'foundation', 'section_title' => 'Фундамент'],
            ['ГЭСН01-01-130-01'],
            20,
            80,
        );
        self::assertContains('01-01-130-01', $byCode->pluck('code')->all());
    }

    public function test_search_finds_norm_by_work_composition_when_name_is_generic(): void
    {
        $versionId = (int) DB::table('estimate_dataset_versions')->insertGetId([
            'source_type' => 'fsnb_2022',
            'version_key' => '2026-06-01',
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
            'code' => '12',
            'name' => 'Кровли',
            'section_type' => 'Сборник',
            'depth' => 0,
            'path' => '12',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('estimate_norms')->insert([
            [
                'collection_id' => $collectionId,
                'section_id' => $sectionId,
                'code' => '12-01-013-01',
                'name' => 'Изоляция покрытий плитами',
                'unit' => 'м2',
                'section_code' => '12-01-013',
                'section_name' => 'Покрытия',
                'work_composition' => json_encode(['Утепление кровли минераловатными плитами'], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $candidates = app(NormativeCandidateSearchService::class)->search(
            EstimateDatasetVersion::query()->findOrFail($versionId),
            [
                'name' => 'Утепление кровли 200 мм',
                'unit' => 'м2',
            ],
            [
                'scope_type' => 'roof',
                'section_title' => 'Кровля',
            ],
            ['утепление', 'кровли'],
            10
        );

        $this->assertSame(['12-01-013-01'], $candidates->pluck('code')->values()->all());
    }

    public function test_search_normalizes_prefixed_normative_code_tokens(): void
    {
        $versionId = (int) DB::table('estimate_dataset_versions')->insertGetId([
            'source_type' => 'fsnb_2022',
            'version_key' => '2026-06-02',
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
            'code' => '01',
            'name' => 'Земляные работы',
            'section_type' => 'Сборник',
            'depth' => 0,
            'path' => '01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('estimate_norms')->insert([
            [
                'collection_id' => $collectionId,
                'section_id' => $sectionId,
                'code' => '01-02-057-01',
                'name' => 'Обратная засыпка грунта',
                'unit' => 'м3',
                'section_code' => '01-02-057',
                'section_name' => 'Земляные работы',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $candidates = app(NormativeCandidateSearchService::class)->search(
            EstimateDatasetVersion::query()->findOrFail($versionId),
            [
                'name' => 'Обратная засыпка пазух',
                'unit' => 'м3',
            ],
            [
                'scope_type' => 'foundation',
                'section_title' => 'Земляные работы',
            ],
            ['фер01-02-057-01'],
            10
        );

        $this->assertSame(['01-02-057-01'], $candidates->pluck('code')->values()->all());
    }

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
