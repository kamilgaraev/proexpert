<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationLearningExample;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\EstimateNormativeMatcher;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimatePricingService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateValidationService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeCandidatePresenter;
use App\BusinessModules\Addons\EstimateGeneration\Services\ResourceAssemblyService;
use App\Integrations\EstimateGeneration\EstimateGenerationLearningBootstrapService;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateSection;
use App\Models\MeasurementUnit;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EstimateNormativeMatcherTest extends TestCase
{
    public function test_matcher_does_not_price_abstract_resource_from_exact_group_price(): void
    {
        $versionId = $this->createVersion('fsnb_2022', '2026-07-19');
        $priceVersionId = $this->createVersion('fsbc', '2026-07-19');
        $collectionId = $this->createCollection($versionId);
        $sectionId = $this->createSection($collectionId, 'Оконные блоки', '10');
        $normId = $this->createNorm(
            $collectionId,
            $sectionId,
            '10-01-034-05',
            'Установка оконных блоков из ПВХ профилей двухстворчатых площадью до 2 м2',
            '100 м2',
            '10-01-034',
        );
        $this->createNormResource(
            $normId,
            '09.4.03.01',
            'Блоки оконные пластиковые',
            'м2',
            100.0,
            'material',
            ['source_tag' => 'AbstractResource'],
        );
        $this->createResourcePrice(
            $priceVersionId,
            '09.4.03.01',
            'Блоки оконные пластиковые',
            'м2',
            7500.0,
            'material',
        );

        $match = app(EstimateNormativeMatcher::class)->searchWorkItemCandidates([
            'name' => 'Монтаж оконных блоков',
            'description' => 'Установка оконных блоков из ПВХ профилей',
            'normative_rate_code' => '10-01-034-05',
            'work_category' => 'openings',
            'unit' => 'м2',
            'quantity' => 23.136,
        ], [
            'scope_type' => 'openings',
            'section_title' => 'Окна и двери',
            'local_estimate_title' => 'Оконные блоки',
        ]);

        self::assertNotNull($match);
        $candidate = collect($match['candidates'])->firstWhere('code', '10-01-034-05');
        self::assertIsArray($candidate);
        $resource = $candidate['resources']['materials'][0];
        self::assertTrue($resource['is_abstract_resource']);
        self::assertTrue($resource['requires_project_resource_selection']);
        self::assertNull($resource['price_source']);
        self::assertSame(0.0, $resource['unit_price']);

        $preview = (new NormativeCandidatePresenter)->present($candidate, [
            'unit' => 'м2',
            'quantity' => 23.136,
        ]);
        self::assertFalse($preview['preview_calculable']);
        self::assertSame(0, $preview['priced_resources_count']);
        self::assertSame(1, $preview['unpriced_resources_count']);
        self::assertNull($preview['unit_price_preview']);
        self::assertSame([], $preview['resource_prices']);
    }

    public function test_latest_fsnb_version_skips_newer_empty_import(): void
    {
        $populatedVersionId = $this->createVersion('fsnb_2022', '2026-05-06');
        $collectionId = $this->createCollection($populatedVersionId);
        $sectionId = $this->createSection($collectionId, 'Земляные работы');
        $this->createNorm($collectionId, $sectionId, '01-01-001-01', 'Разработка грунта', '1000 м3');
        $this->createVersion('fsnb_2022', '2026-05-07');

        $version = app(EstimateNormativeMatcher::class)->latestFsnbVersion();

        $this->assertNotNull($version);
        $this->assertSame($populatedVersionId, $version->id);
    }

    public function test_matcher_selects_best_norm_and_groups_resources(): void
    {
        $versionId = $this->createVersion('fsnb_2022', '2026-05-07');
        $priceVersionId = $this->createVersion('fsbc', '2026-05-07');
        $collectionId = $this->createCollection($versionId);
        $sectionId = $this->createSection($collectionId, 'Фундаменты');
        $normId = $this->createNorm($collectionId, $sectionId, '01-01-001-01', 'Бетонирование фундаментов', 'м3');
        $this->createNorm($collectionId, $sectionId, '08-02-001-01', 'Кладка наружных стен', 'м3');
        $this->createNormResource($normId, '01.1.01.01-0001', 'Бетон тяжелый', 'м3', 1.02, 'material');
        $this->createNormResource($normId, '1-100-34', 'Затраты труда рабочих', 'чел.-ч', 2.5, 'labor');
        $this->createNormResource($normId, '91.05.13-021', 'Автомобиль бортовой', 'маш.-ч', 0.3, 'machine');
        $this->createResourcePrice($priceVersionId, '01.1.01.01-0001', 'Бетон тяжелый', 'м3', 4200, 'material');
        $this->createResourcePrice($priceVersionId, '91.05.13-021', 'Автомобиль бортовой', 'маш.-ч', 1500, 'machine');

        $match = app(EstimateNormativeMatcher::class)->matchWorkItem([
            'name' => 'Бетонирование фундаментов',
            'description' => 'Устройство фундаментных конструкций',
            'work_category' => 'concrete',
            'unit' => 'м3',
        ], [
            'scope_type' => 'foundation',
            'section_title' => 'Фундаменты',
            'local_estimate_title' => 'Фундамент',
        ]);

        $this->assertNotNull($match);
        $this->assertSame('01-01-001-01', $match['selected']['code']);
        $this->assertSame('2026-05-07', $match['version']['version_key']);
        $this->assertSame('fsbc', $match['price_version']['source_type']);
        $this->assertCount(1, $match['selected']['resources']['materials']);
        $this->assertCount(1, $match['selected']['resources']['labor']);
        $this->assertCount(1, $match['selected']['resources']['machinery']);
        $this->assertSame(4200.0, $match['selected']['resources']['materials'][0]['unit_price']);
    }

    public function test_matcher_includes_learning_evidence_in_candidate_scoring(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $versionId = $this->createVersion('fsnb_2022', '2026-05-30');
        $priceVersionId = $this->createVersion('fsbc', '2026-05-30');
        $collectionId = $this->createCollection($versionId);
        $sectionId = $this->createSection($collectionId, 'Фундаменты');
        $normId = $this->createNorm($collectionId, $sectionId, '01-01-001-01', 'Бетонирование фундаментной ленты', 'м3');
        $this->createNormResource($normId, '01.1.01.01-0001', 'Бетон тяжелый', 'м3', 1.02, 'material');
        $this->createResourcePrice($priceVersionId, '01.1.01.01-0001', 'Бетон тяжелый', 'м3', 4200, 'material');

        EstimateGenerationLearningExample::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'source_type' => 'user_selection',
            'source_entity_type' => 'estimate_generation_package_item',
            'source_entity_id' => 700001,
            'work_name' => 'Бетонирование фундаментной ленты B22.5',
            'work_unit' => 'м3',
            'work_quantity' => 13.8,
            'work_intent' => ['scope' => 'foundation', 'action' => 'concreting', 'system' => null],
            'estimate_norm_id' => $normId,
            'norm_code' => '01-01-001-01',
            'normative_name' => 'Бетонирование фундаментной ленты',
            'normative_unit' => 'м3',
            'decision_status' => 'selected_by_user',
            'confidence' => 1.0,
            'is_positive' => true,
            'source_quality_score' => 1.0,
            'context_payload' => ['section_title' => 'Фундамент'],
            'source_refs' => [],
            'quality_flags' => ['unit_compatible'],
            'accepted_at' => now(),
        ]);

        $match = app(EstimateNormativeMatcher::class)->matchWorkItem([
            'name' => 'Бетонирование фундаментной ленты B22.5',
            'unit' => 'м3',
            'quantity' => 13.8,
        ], [
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'scope_type' => 'foundation',
            'section_title' => 'Фундамент',
            'local_estimate_title' => 'Фундамент',
        ]);

        $this->assertNotNull($match);
        $this->assertSame('01-01-001-01', $match['selected']['code']);
        $this->assertSame(1, $match['selected']['learning_positive_count']);
        $this->assertSame(0, $match['selected']['learning_negative_count']);
        $this->assertGreaterThan(0, $match['selected']['learning_score']);
        $this->assertContains('learning_positive_evidence', $match['selected']['match_reasons']);
        $this->assertSame('user_selection', $match['selected']['learning_sources'][0]['source_type']);
    }

    public function test_matcher_uses_bootstrapped_imported_learning_example_in_candidate_scoring(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $versionId = $this->createVersion('fsnb_2022', '2026-05-31');
        $priceVersionId = $this->createVersion('fsbc', '2026-05-31');
        $collectionId = $this->createCollection($versionId);
        $sectionId = $this->createSection($collectionId, 'Фундаменты');
        $normId = $this->createNorm($collectionId, $sectionId, '01-01-001-01', 'Бетонирование фундаментной ленты', 'м3');
        $this->createNormResource($normId, '01.1.01.01-0001', 'Бетон тяжелый', 'м3', 1.02, 'material');
        $this->createResourcePrice($priceVersionId, '01.1.01.01-0001', 'Бетон тяжелый', 'м3', 4200, 'material');
        $this->createImportedEstimateItem($organization, $project, '01-01-001-01');

        $bootstrap = app(EstimateGenerationLearningBootstrapService::class)->bootstrap([
            'organization_id' => $organization->id,
            'write' => true,
        ]);

        $this->assertSame(1, $bootstrap['created_examples']);

        $match = app(EstimateNormativeMatcher::class)->matchWorkItem([
            'name' => 'Бетонирование фундаментной ленты B22.5',
            'unit' => 'м3',
            'quantity' => 13.8,
        ], [
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'scope_type' => 'foundation',
            'section_title' => 'Фундамент',
            'local_estimate_title' => 'Фундамент',
        ]);

        $this->assertNotNull($match);
        $this->assertSame('01-01-001-01', $match['selected']['code']);
        $this->assertSame(1, $match['selected']['learning_positive_count']);
        $this->assertGreaterThan(0, $match['selected']['learning_score']);
        $this->assertContains('learning_positive_evidence', $match['selected']['match_reasons']);
        $this->assertSame('imported_estimate', $match['selected']['learning_sources'][0]['source_type']);
    }

    public function test_matcher_does_not_drop_roof_norm_after_code_ordered_earthwork_pool(): void
    {
        $versionId = $this->createVersion('fsnb_2022', '2026-05-08');
        $priceVersionId = $this->createVersion('fsbc', '2026-05-08');
        $collectionId = $this->createCollection($versionId);
        $earthworkSectionId = $this->createSection($collectionId, 'Земляные работы', '01');
        $roofSectionId = $this->createSection($collectionId, 'Кровли', '12');

        for ($index = 1; $index <= 60; $index++) {
            $this->createNorm(
                $collectionId,
                $earthworkSectionId,
                sprintf('01-01-063-%02d', $index),
                'Утепление грунта в траншеях',
                'км',
                '01-01-063'
            );
        }

        $normId = $this->createNorm(
            $collectionId,
            $roofSectionId,
            '12-01-013-01',
            'Утепление покрытий кровли минераловатными плитами',
            'м2',
            '12-01-013'
        );
        $this->createNormResource($normId, '12.1.01.01-0001', 'Плиты минераловатные', 'м2', 1.05, 'material');
        $this->createResourcePrice($priceVersionId, '12.1.01.01-0001', 'Плиты минераловатные', 'м2', 800, 'material');

        $match = app(EstimateNormativeMatcher::class)->matchWorkItem([
            'name' => 'Утепление кровли 200 мм',
            'unit' => 'м2',
            'quantity' => 194.25,
        ], [
            'scope_type' => 'roof',
            'section_title' => 'Кровля',
            'local_estimate_title' => 'Кровля',
        ]);

        $this->assertNotNull($match);
        $this->assertSame('12-01-013-01', $match['selected']['code']);
        $this->assertSame('м2', $match['selected']['unit']);
    }

    public function test_matcher_prefers_length_cable_norm_over_piece_equipment_norm(): void
    {
        $versionId = $this->createVersion('fsnb_2022', '2026-05-09');
        $priceVersionId = $this->createVersion('fsbc', '2026-05-09');
        $collectionId = $this->createCollection($versionId, 'gesnm');
        $electricalSectionId = $this->createSection($collectionId, 'Электромонтажные работы', '08');

        for ($index = 1; $index <= 55; $index++) {
            $this->createNorm(
                $collectionId,
                $electricalSectionId,
                sprintf('08-01-025-%02d', $index),
                'Прокладка кабельных линий для блочных подстанций',
                'шт',
                '08-01-025'
            );
        }

        $normId = $this->createNorm(
            $collectionId,
            $electricalSectionId,
            '08-02-147-01',
            'Прокладка кабеля в готовых каналах',
            'м',
            '08-02-147'
        );
        $this->createNormResource($normId, '08.2.01.01-0001', 'Кабель силовой', 'м', 1.0, 'material');
        $this->createResourcePrice($priceVersionId, '08.2.01.01-0001', 'Кабель силовой', 'м', 250, 'material');

        $match = app(EstimateNormativeMatcher::class)->matchWorkItem([
            'name' => 'Прокладка кабельных линий',
            'unit' => 'м',
            'quantity' => 834.68,
        ], [
            'scope_type' => 'engineering',
            'section_title' => 'Электроснабжение',
            'local_estimate_title' => 'Инженерные сети',
        ]);

        $this->assertNotNull($match);
        $this->assertSame('08-02-147-01', $match['selected']['code']);
        $this->assertSame('м', $match['selected']['unit']);
    }

    public function test_resource_assembly_uses_normative_resources_without_template_prices(): void
    {
        $versionId = $this->createVersion('fsnb_2022', '2026-05-07');
        $priceVersionId = $this->createVersion('fsbc', '2026-05-07');
        $collectionId = $this->createCollection($versionId);
        $sectionId = $this->createSection($collectionId, 'Фундаменты');
        $normId = $this->createNorm($collectionId, $sectionId, '01-01-001-01', 'Бетонирование фундаментов', 'м3');
        $this->createNormResource($normId, '01.1.01.01-0001', 'Бетон тяжелый', 'м3', 1.5, 'material');
        $this->createResourcePrice($priceVersionId, '01.1.01.01-0001', 'Бетон тяжелый', 'м3', 1000, 'material');

        $items = app(ResourceAssemblyService::class)->enrich([[
            'key' => 'foundation-work-1',
            'name' => 'Бетонирование фундаментов',
            'description' => 'Устройство фундаментных конструкций',
            'work_category' => 'concrete',
            'unit' => 'м3',
            'quantity' => 2,
            'confidence' => 0.7,
            'validation_flags' => [],
        ]], [
            'scope_type' => 'foundation',
            'section_title' => 'Фундаменты',
            'local_estimate_title' => 'Фундамент',
        ]);

        $item = app(EstimatePricingService::class)->price($items)[0];

        $this->assertSame('matched', $item['normative_match']['status']);
        $this->assertSame('01-01-001-01', $item['normative_rate_code']);
        $this->assertSame(3.0, $item['materials'][0]['quantity']);
        $this->assertSame(3000.0, $item['materials'][0]['total_price']);
        $this->assertSame('01.1.01.01-0001', $item['materials'][0]['normative_ref']['resource_code']);
        $this->assertNotContains('normative_not_found', $item['validation_flags']);
    }

    public function test_resource_assembly_accepts_scaled_normative_units_and_scales_resources(): void
    {
        $versionId = $this->createVersion('fsnb_2022', '2026-05-07');
        $priceVersionId = $this->createVersion('fsbc', '2026-05-07');
        $collectionId = $this->createCollection($versionId);
        $sectionId = $this->createSection($collectionId, 'Земляные работы');
        $normId = $this->createNorm($collectionId, $sectionId, '01-01-006-01', 'Разработка грунта экскаваторами', '1000 м3');
        $this->createNormResource($normId, '01.1.01.01-0001', 'Песок строительный', 'м3', 2.0, 'material');
        $this->createResourcePrice($priceVersionId, '01.1.01.01-0001', 'Песок строительный', '1000 м3', 500000, 'material');

        $items = app(ResourceAssemblyService::class)->enrich([[
            'key' => 'earthworks-work-1',
            'name' => 'Разработка грунта под фундамент',
            'description' => 'Разработка грунта экскаваторами',
            'work_category' => 'earthworks',
            'normative_rate_code' => '01-01-006-01',
            'unit' => 'м3',
            'quantity' => 500,
            'confidence' => 0.7,
            'validation_flags' => [],
        ]], [
            'scope_type' => 'foundation',
            'section_title' => 'Земляные работы',
            'local_estimate_title' => 'Земляные работы',
        ]);

        $item = $items[0];

        $this->assertSame('matched', $item['normative_match']['status']);
        $this->assertSame('01-01-006-01', $item['normative_rate_code']);
        $this->assertSame(1.0, $item['materials'][0]['quantity']);
        $this->assertSame(500.0, $item['materials'][0]['unit_price']);
        $this->assertSame(500.0, $item['materials'][0]['total_price']);
        $this->assertNotContains('unit_mismatch', $item['normative_match']['warnings']);
    }

    public function test_resource_assembly_keeps_unit_mismatch_candidate_unpriced(): void
    {
        $versionId = $this->createVersion('fsnb_2022', '2026-05-07');
        $priceVersionId = $this->createVersion('fsbc', '2026-05-07');
        $collectionId = $this->createCollection($versionId);
        $sectionId = $this->createSection($collectionId, 'Фундаменты');
        $normId = $this->createNorm($collectionId, $sectionId, '01-01-001-01', 'Бетонирование фундаментов', 'м3');
        $this->createNormResource($normId, '01.1.01.01-0001', 'Бетон тяжелый', 'м3', 1.5, 'material');
        $this->createResourcePrice($priceVersionId, '01.1.01.01-0001', 'Бетон тяжелый', 'м3', 1000, 'material');

        $items = app(ResourceAssemblyService::class)->enrich([[
            'key' => 'foundation-work-1',
            'name' => 'Опалубка ленточного фундамента',
            'description' => 'Опалубка ленточного фундамента',
            'work_category' => 'foundation',
            'normative_rate_code' => '01-01-001-01',
            'unit' => 'м2',
            'quantity' => 2,
            'confidence' => 0.6,
            'validation_flags' => [],
        ]], [
            'scope_type' => 'foundation',
            'section_title' => 'Фундаменты',
            'local_estimate_title' => 'Фундамент',
        ]);

        $item = app(EstimatePricingService::class)->price($items)[0];

        $this->assertSame('candidate', $item['normative_match']['status']);
        $this->assertSame([], $item['materials']);
        $this->assertSame(0.0, $item['total_cost']);
        $this->assertContains('unit_mismatch', $item['normative_match']['warnings']);
        $this->assertContains('requires_normative_review', $item['validation_flags']);
        $this->assertContains('normative_candidate_only', $item['validation_flags']);
    }

    public function test_validation_preserves_normative_flags(): void
    {
        $draft = app(EstimateValidationService::class)->validate([
            'local_estimates' => [[
                'key' => 'local-1',
                'title' => 'Локальная смета',
                'scope_type' => 'custom',
                'source_refs' => ['doc-1'],
                'sections' => [[
                    'key' => 'section-1',
                    'title' => 'Раздел',
                    'source_refs' => ['doc-1'],
                    'work_items' => [[
                        'key' => 'work-1',
                        'name' => 'Работа',
                        'quantity' => 1,
                        'quantity_basis' => 'manual',
                        'total_cost' => 0,
                        'materials' => [],
                        'labor' => [],
                        'machinery' => [],
                        'confidence' => 0.5,
                        'validation_flags' => ['normative_not_found'],
                    ]],
                ]],
            ]],
        ]);

        $flags = $draft['local_estimates'][0]['sections'][0]['work_items'][0]['validation_flags'];

        $this->assertContains('normative_not_found', $flags);
        $this->assertContains('missing_price', $flags);
        $this->assertContains('missing_resources', $flags);
        $this->assertContains('normative_not_found', $draft['problem_flags']);
    }

    public function test_validation_marks_priced_review_flags_without_unresolved_normative_blocker(): void
    {
        $draft = app(EstimateValidationService::class)->validate([
            'local_estimates' => [[
                'key' => 'local-1',
                'title' => 'Локальная смета',
                'scope_type' => 'foundation',
                'source_refs' => ['doc-1'],
                'sections' => [[
                    'key' => 'section-1',
                    'title' => 'Раздел',
                    'source_refs' => ['doc-1'],
                    'work_items' => [[
                        'key' => 'work-1',
                        'name' => 'Работа',
                        'quantity' => 1,
                        'quantity_basis' => 'по чертежу',
                        'total_cost' => 1000,
                        'materials' => [['total_price' => 1000]],
                        'labor' => [],
                        'machinery' => [],
                        'confidence' => 0.8,
                        'validation_flags' => ['requires_normative_review'],
                        'normative_match' => ['status' => 'matched'],
                    ]],
                ]],
            ]],
        ]);

        $this->assertSame('review_required', $draft['quality_summary']['status']);
        $this->assertSame(1, $draft['quality_summary']['normative_items']['accepted']);
        $this->assertSame(0, $draft['quality_summary']['normative_items']['requires_review']);
        $this->assertContains('requires_normative_review', $draft['quality_summary']['warning_flags']);
    }

    public function test_validation_summarizes_review_priced_and_candidate_only_normative_items(): void
    {
        $draft = app(EstimateValidationService::class)->validate([
            'local_estimates' => [[
                'key' => 'local-1',
                'title' => 'Локальная смета',
                'scope_type' => 'roof',
                'source_refs' => ['doc-1'],
                'sections' => [[
                    'key' => 'section-1',
                    'title' => 'Кровля',
                    'source_refs' => ['doc-1'],
                    'work_items' => [
                        [
                            'key' => 'work-1',
                            'name' => 'Утепление кровли',
                            'quantity' => 10,
                            'quantity_basis' => 'по чертежу',
                            'total_cost' => 1000,
                            'materials' => [['total_price' => 1000]],
                            'labor' => [],
                            'machinery' => [],
                            'confidence' => 0.8,
                            'validation_flags' => ['requires_normative_review'],
                            'normative_match' => [
                                'status' => 'matched',
                                'warnings' => ['low_confidence'],
                                'decision' => [
                                    'status' => 'review_priced',
                                    'warnings' => ['requires_normative_review'],
                                ],
                            ],
                        ],
                        [
                            'key' => 'work-2',
                            'name' => 'Ошибочная норма',
                            'quantity' => 1,
                            'quantity_basis' => 'по чертежу',
                            'total_cost' => 0,
                            'materials' => [],
                            'labor' => [],
                            'machinery' => [],
                            'confidence' => 0.7,
                            'validation_flags' => ['normative_candidate_only', 'requires_normative_review'],
                            'normative_match' => [
                                'status' => 'candidate',
                                'warnings' => ['unit_mismatch'],
                            ],
                        ],
                    ],
                ]],
            ]],
        ]);

        $items = $draft['quality_summary']['normative_items'];

        $this->assertSame(0, $items['accepted']);
        $this->assertSame(1, $items['review_priced']);
        $this->assertSame(1, $items['candidate_only']);
        $this->assertSame(1, $items['unit_mismatch']);
    }

    private function createVersion(string $sourceType, string $versionKey): int
    {
        return (int) DB::table('estimate_dataset_versions')->insertGetId([
            'source_type' => $sourceType,
            'version_key' => $versionKey,
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
    }

    private function createCollection(int $versionId, string $normType = 'gesn'): int
    {
        return (int) DB::table('estimate_norm_collections')->insertGetId([
            'dataset_version_id' => $versionId,
            'code' => $normType,
            'name' => 'ГЭСН',
            'norm_type' => $normType,
            'source_file' => 'ГЭСН.xml',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createSection(int $collectionId, string $name, string $code = '01'): int
    {
        return (int) DB::table('estimate_norm_sections')->insertGetId([
            'collection_id' => $collectionId,
            'parent_id' => null,
            'code' => $code,
            'name' => $name,
            'section_type' => 'Сборник',
            'depth' => 0,
            'path' => $code,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createNorm(
        int $collectionId,
        int $sectionId,
        string $code,
        string $name,
        string $unit,
        ?string $sectionCode = null
    ): int {
        return (int) DB::table('estimate_norms')->insertGetId([
            'collection_id' => $collectionId,
            'section_id' => $sectionId,
            'code' => $code,
            'name' => $name,
            'unit' => $unit,
            'section_code' => $sectionCode ?? substr($code, 0, 8),
            'section_name' => $name,
            'work_composition' => json_encode(['Подготовка основания', 'Укладка бетонной смеси'], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $rawPayload */
    private function createNormResource(
        int $normId,
        string $code,
        string $name,
        string $unit,
        float $quantity,
        string $type,
        array $rawPayload = [],
    ): void {
        DB::table('estimate_norm_resources')->insert([
            'estimate_norm_id' => $normId,
            'construction_resource_id' => null,
            'resource_code' => $code,
            'resource_name' => $name,
            'unit' => $unit,
            'quantity' => $quantity,
            'resource_type' => $type,
            'raw_payload' => $rawPayload !== []
                ? json_encode($rawPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
                : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createResourcePrice(int $versionId, string $code, string $name, string $unit, float $price, string $type): void
    {
        DB::table('estimate_resource_prices')->insert([
            'dataset_version_id' => $versionId,
            'construction_resource_id' => null,
            'resource_code' => $code,
            'resource_name' => $name,
            'unit' => $unit,
            'base_price' => $price,
            'price_type' => $type,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createImportedEstimateItem(Organization $organization, Project $project, string $normCode): EstimateItem
    {
        $estimate = Estimate::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'number' => 'BOOT-001',
            'name' => 'Bootstrap estimate',
            'type' => 'local',
            'status' => 'draft',
            'estimate_date' => now()->toDateString(),
        ]);
        $section = EstimateSection::query()->create([
            'estimate_id' => $estimate->id,
            'section_number' => '1',
            'full_section_number' => '1',
            'name' => 'Фундамент',
            'sort_order' => 1,
        ]);
        $unit = MeasurementUnit::query()->create([
            'organization_id' => $organization->id,
            'name' => 'кубический метр',
            'short_name' => 'м3',
            'type' => 'work',
        ]);

        return EstimateItem::query()->create([
            'estimate_id' => $estimate->id,
            'estimate_section_id' => $section->id,
            'position_number' => '1',
            'name' => 'Бетонирование фундаментной ленты B22.5',
            'measurement_unit_id' => $unit->id,
            'quantity' => 13.8,
            'unit_price' => 5000,
            'total_amount' => 69000,
            'normative_rate_code' => 'ФСНБ '.$normCode,
            'item_type' => 'work',
        ]);
    }
}
