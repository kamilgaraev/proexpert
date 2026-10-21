<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Planning;

use App\BusinessModules\Addons\EstimateGeneration\Enums\EstimateGenerationMode;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeContextPinResolver;
use App\BusinessModules\Addons\EstimateGeneration\Planning\WorkPlanCompiler;
use App\BusinessModules\Addons\EstimateGeneration\Planning\WorkPlannerResponseData;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateDecompositionService;
use App\BusinessModules\Addons\EstimateGeneration\Services\ConstructionSemanticParser;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimatorScopeInferenceService;
use App\BusinessModules\Addons\EstimateGeneration\Services\NormativeWorkItemPlannerService;
use App\BusinessModules\Addons\EstimateGeneration\Services\PackagePlannerService;
use App\BusinessModules\Addons\EstimateGeneration\Services\ProjectDocumentNormativeReferenceExtractor;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkPlanCompilerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $container = new Container;
        $container->instance('app', new class
        {
            public function getLocale(): string
            {
                return 'ru';
            }
        });
        $container->instance('config', new Repository(['app' => ['fallback_locale' => 'ru']]));
        $container->instance('translator', new Translator(new FileLoader(new Filesystem, dirname(__DIR__, 4).'/lang'), 'ru'));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        parent::tearDown();
    }

    public function test_compilation_rejects_work_items_over_the_frozen_ceiling(): void
    {
        $analysis = $this->analysis();
        $analysis['source_documents'] = [['id' => 82, 'status' => 'ready', 'quality' => ['level' => 'good'],
            'document_understanding' => ['role_for_estimation' => 'quantity_source'],
            'text' => 'ФЕР 06-01-001-01 Укладка бетона 10 м3']];
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('estimate_work_items_exceed_approved_plan');
        $this->compiler()->compile($analysis, deferNormativePin: true, workItemLimit: 0);
    }

    public function test_quantity_coverage_warnings_are_attached_only_to_the_affected_packages(): void
    {
        $analysis = $this->analysis();
        $analysis['document_context']['quantity_coverage_warnings'] = [
            [
                'quantity_key' => 'stairs.flights',
                'reason' => 'stair_construction_geometry_missing',
                'package_key' => 'stairs',
            ],
            [
                'quantity_key' => 'electrical.grounding',
                'reason' => 'grounding_installation_type_missing',
                'package_key' => 'electrical',
            ],
            [
                'quantity_key' => 'stairs.railings',
                'reason' => 'stair_railing_geometry_missing',
                'package_key' => 'stairs',
            ],
        ];

        $payload = $this->compiler()->compile($analysis, deferNormativePin: true);
        $packages = array_column($payload['local_estimates'], null, 'key');

        self::assertContains(
            'Лестничные марши и площадки не включены: в документах нет конструкции, размеров и объёмов лестницы.',
            $packages['stairs']['assumptions'],
        );
        self::assertContains(
            'Контур заземления не включён: в документах не указан тип и схема устройства заземления.',
            $packages['electrical']['assumptions'],
        );
        self::assertContains(
            'Лестничные ограждения не включены: в документах нет длины, материала и конструкции ограждений.',
            $packages['stairs']['assumptions'],
        );
        self::assertSame(2, count($packages['stairs']['coverage_warnings']));
        self::assertSame('stairs', $packages['stairs']['coverage_warnings'][0]['package_key']);
        self::assertContains(
            $packages['stairs']['coverage_warnings'][0]['message'],
            $packages['stairs']['assumptions'],
        );
        self::assertNotContains(
            'Контур заземления не включён: в документах не указан тип и схема устройства заземления.',
            $packages['stairs']['assumptions'],
        );
        self::assertNotContains(
            'Лестничные марши и площадки не включены: в документах нет конструкции, размеров и объёмов лестницы.',
            $packages['electrical']['assumptions'],
        );
    }

    public function test_repeated_coverage_reason_is_presented_once_per_package(): void
    {
        $analysis = $this->analysis();
        $analysis['document_context']['quantity_coverage_warnings'] = [
            ['quantity_key' => 'sewerage.outlet_route', 'reason' => 'sewerage_design_takeoff_missing', 'package_key' => 'foundation'],
            ['quantity_key' => 'sewerage.risers', 'reason' => 'sewerage_design_takeoff_missing', 'package_key' => 'foundation'],
            ['quantity_key' => 'sewerage.revisions', 'reason' => 'sewerage_design_takeoff_missing', 'package_key' => 'foundation'],
        ];

        $payload = $this->compiler()->compile($analysis, deferNormativePin: true);
        $package = array_column($payload['local_estimates'], null, 'key')['foundation'];

        self::assertCount(1, $package['coverage_warnings']);
        self::assertSame('sewerage_design_takeoff_missing', $package['coverage_warnings'][0]['reason']);
    }

    public function test_runtime_compilation_is_identical_to_legacy_algorithm(): void
    {
        $analysis = $this->analysis();
        $packagePlanner = new PackagePlannerService;
        $decomposition = new EstimateDecompositionService;
        $workPlanner = new NormativeWorkItemPlannerService(
            new ProjectDocumentNormativeReferenceExtractor,
            new EstimatorScopeInferenceService,
        );
        $pins = new NormativeContextPinResolver;
        $profile = $packagePlanner->profileFromAnalysis($analysis);
        $plan = $packagePlanner->plan($profile);
        $localEstimates = $decomposition->decomposePackagePlan($analysis, $plan);
        foreach ($localEstimates as $localIndex => $localEstimate) {
            foreach ($localEstimate['sections'] as $sectionIndex => $section) {
                $localEstimates[$localIndex]['sections'][$sectionIndex]['work_items'] = $workPlanner->build($localEstimate, $section, $analysis);
            }
        }
        $expected = [
            'object_profile' => $profile->toArray(),
            'package_plan' => $plan->toArray(),
            'document_requirements' => $packagePlanner->documentRequirements($profile),
            'generation_mode' => EstimateGenerationMode::fromInput($profile->planningSignals['generation_mode'] ?? null)->value,
            'regional_context' => $analysis['regional_context'],
            'normative_context_pin' => $pins->resolve($analysis['regional_context']),
            'local_estimates' => $localEstimates,
        ];

        $actual = (new WorkPlanCompiler($packagePlanner, $decomposition, $workPlanner, $pins))->compile($analysis);

        self::assertSame($expected, $actual);
        self::assertSame(hash('sha256', json_encode($expected, JSON_THROW_ON_ERROR)), hash('sha256', json_encode($actual, JSON_THROW_ON_ERROR)));
    }

    public function test_model_response_supplies_semantic_intents_without_final_norms_or_prices(): void
    {
        $source = new WorkPlannerResponseData([[
            'section_key' => 'foundation-section-1',
            'title' => 'Фундамент',
            'scope_type' => 'foundation',
            'source_refs' => ['quantity:q1'],
            'work_intents' => [[
                'intent_key' => 'foundation.concrete',
                'name' => 'Устройство монолитного фундамента',
                'category' => 'foundation',
                'unit' => 'm3',
                'quantity' => '12.5',
                'quantity_key' => 'concrete_volume', 'quantity_source_refs' => ['quantity:q1'],
                'confidence' => 0.91,
                'work_intent' => ['material' => 'concrete', 'action' => 'concreting', 'scope' => 'foundation',
                    'object' => 'foundation', 'dimensions' => ['volume'], 'preferred_section_prefixes' => ['06']],
            ]],
        ]],
        );

        $payload = $this->compiler()->compile($this->analysis(), $source);
        $items = array_merge(...array_map(static fn (array $estimate): array => array_merge(...array_column($estimate['sections'], 'work_items')), $payload['local_estimates']));
        $item = collect($items)->firstWhere('key', 'foundation.concrete');

        self::assertIsArray($item);
        self::assertSame('12.5', $item['quantity']);
        self::assertSame('not_calculated', $item['pricing_status']);
        self::assertNull($item['normative_rate_code']);
        self::assertSame('concreting', $item['work_intent']['action']);
        self::assertArrayNotHasKey('norm_id', $item);
    }

    public function test_model_intent_keeps_the_catalog_signed_scenario_for_later_normative_matching(): void
    {
        $decomposition = $this->createMock(EstimateDecompositionService::class);
        $decomposition->method('decomposePackagePlan')->willReturn([[
            'key' => 'walls',
            'title' => 'Стены',
            'scope_type' => 'walls',
            'sections' => [['key' => 'walls', 'title' => 'Стены', 'source_refs' => []]],
        ]]);
        $source = new WorkPlannerResponseData([[
            'section_key' => 'walls',
            'scope_type' => 'walls',
            'work_intents' => [[
                'intent_key' => 'walls-lintels',
                'name' => 'Устройство перемычек',
                'category' => 'walls',
                'unit' => 'шт',
                'quantity' => '8',
                'quantity_key' => 'walls.lintels',
                'quantity_source_refs' => ['doc:1'],
                'confidence' => 0.91,
            ]],
        ]]);
        $compiler = new WorkPlanCompiler(
            new PackagePlannerService,
            $decomposition,
            new NormativeWorkItemPlannerService(new ProjectDocumentNormativeReferenceExtractor, new EstimatorScopeInferenceService),
            new NormativeContextPinResolver,
        );

        $payload = $compiler->compile([
            'object' => ['object_type' => 'house', 'area' => 180],
            'regional_context' => [],
            'planning_signals' => ['generation_mode' => 'ai_assisted'],
        ], $source, true);

        $item = $payload['local_estimates'][0]['sections'][0]['work_items'][0];
        self::assertSame('07-01-021-01', $item['specialization_scenario']['normative_rate_code'] ?? null);
    }

    public function test_normative_pin_is_resolved_from_final_canonical_work_item_units(): void
    {
        $pins = $this->createMock(NormativeContextPinResolver::class);
        $capturedIntents = [];
        $pins->expects(self::once())
            ->method('resolve')
            ->with([], self::callback(static function (array $intents) use (&$capturedIntents): bool {
                $capturedIntents = $intents;

                return true;
            }))
            ->willReturn(['status' => 'pinned']);
        $compiler = new WorkPlanCompiler(
            new PackagePlannerService,
            new EstimateDecompositionService,
            new NormativeWorkItemPlannerService(new ProjectDocumentNormativeReferenceExtractor, new EstimatorScopeInferenceService),
            $pins,
        );

        $pin = $compiler->resolveNormativeContextPin([], [[
            'sections' => [[
                'work_items' => [[
                    'item_type' => 'priced_work',
                    'key' => 'roof-norm-intent-1',
                    'name' => 'Устройство полов',
                    'normative_search_text' => 'Устройство полов',
                    'unit' => 'm2',
                    'normative_rate_code' => null,
                    'work_intent' => [
                        'material' => 'керамическая плитка',
                        'preferred_section_prefixes' => ['11'],
                    ],
                ]],
            ]],
        ]], 'house');

        self::assertSame(['status' => 'pinned'], $pin);
        self::assertSame('roof-norm-intent-1', $capturedIntents[0]['work_item_key']);
    }

    public function test_normative_pin_preserves_every_allowed_section_prefix(): void
    {
        $pins = $this->createMock(NormativeContextPinResolver::class);
        $pins->expects(self::once())
            ->method('resolve')
            ->with([], [[
                'work_item_key' => 'foundation-norm-intent-1',
                'search_text' => 'Бетонирование фундаментов',
                'unit' => 'm3',
                'code' => null,
                'action' => 'concreting',
                'scope' => 'foundation',
                'system' => null,
                'object' => 'foundation',
                'normative_section' => null,
                'normative_sections' => ['01', '06'],
                'material' => 'concrete',
            ]])
            ->willReturn(['status' => 'pinned']);

        $compiler = new WorkPlanCompiler(
            new PackagePlannerService,
            new EstimateDecompositionService,
            new NormativeWorkItemPlannerService(new ProjectDocumentNormativeReferenceExtractor, new EstimatorScopeInferenceService),
            $pins,
        );
        $pin = $compiler->resolveNormativeContextPin([], [
            [
                'sections' => [
                    [
                        'work_items' => [
                            [
                                'item_type' => 'priced_work',
                                'key' => 'foundation-norm-intent-1',
                                'name' => 'Бетонирование фундаментов',
                                'unit' => 'm3',
                                'work_intent' => ['preferred_section_prefixes' => ['01', '06']],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        self::assertSame(['status' => 'pinned'], $pin);
    }

    public function test_normative_pin_preserves_signed_specialization_contract(): void
    {
        $scenario = (new \App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\ResidentialMaterialScenarioCatalog)
            ->issue('lighting.lines', 'residential');
        self::assertIsArray($scenario);
        $pins = $this->createMock(NormativeContextPinResolver::class);
        $pins->expects(self::once())
            ->method('resolve')
            ->with([], self::callback(static fn (array $intents): bool => ($intents[0]['specialization_scenario'] ?? null) === $scenario
                && ($intents[0]['action'] ?? null) === 'cable_installation'
                && ($intents[0]['code'] ?? null) === $scenario['normative_rate_code']
                && ($intents[0]['search_text'] ?? null) === $scenario['normative_search_text']
                && ($intents[0]['normative_sections'] ?? null) === ['08']))
            ->willReturn(['status' => 'pinned']);
        $compiler = new WorkPlanCompiler(
            new PackagePlannerService,
            new EstimateDecompositionService,
            new NormativeWorkItemPlannerService(new ProjectDocumentNormativeReferenceExtractor, new EstimatorScopeInferenceService),
            $pins,
        );

        $pin = $compiler->resolveNormativeContextPin([], [[
            'sections' => [[
                'work_items' => [[
                    'item_type' => 'priced_work',
                    'key' => 'lighting-norm-intent-1',
                    'name' => 'Прокладка линий освещения',
                    'normative_search_text' => 'Ненадёжное имя работы от модели',
                    'normative_rate_code' => null,
                    'unit' => 'm',
                    'specialization_scenario' => $scenario,
                    'metadata' => ['quantity_key' => 'lighting.lines'],
                ]],
            ]],
        ]], 'house');

        self::assertSame(['status' => 'pinned'], $pin);
    }

    public function test_normative_pin_restores_catalog_scenario_after_ai_reconciliation(): void
    {
        $catalog = new \App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\ResidentialMaterialScenarioCatalog;
        $scenario = $catalog->issue('slabs.rebar', 'residential');
        self::assertIsArray($scenario);
        $pins = $this->createMock(NormativeContextPinResolver::class);
        $pins->expects(self::once())
            ->method('resolve')
            ->with([], self::callback(static fn (array $intents): bool => ($intents[0]['specialization_scenario'] ?? null) === $scenario
                && ($intents[0]['code'] ?? null) === '06-23-003-05'
                && ($intents[0]['object_type'] ?? null) === 'house'))
            ->willReturn(['status' => 'pinned']);
        $compiler = new WorkPlanCompiler(
            new PackagePlannerService,
            new EstimateDecompositionService,
            new NormativeWorkItemPlannerService(new ProjectDocumentNormativeReferenceExtractor, new EstimatorScopeInferenceService),
            $pins,
        );

        $pin = $compiler->resolveNormativeContextPin([], [[
            'sections' => [[
                'work_items' => [[
                    'item_type' => 'priced_work',
                    'key' => 'slabs-norm-intent-1',
                    'name' => 'Армирование монолитного перекрытия',
                    'normative_search_text' => 'Армирование монолитного перекрытия',
                    'normative_rate_code' => null,
                    'unit' => 'kg',
                    'quantity_formula' => 'slabs.rebar',
                    'metadata' => ['quantity_key' => 'slabs.rebar'],
                ]],
            ]],
        ]], 'house');

        self::assertSame(['status' => 'pinned'], $pin);
    }





    private function compiler(): WorkPlanCompiler
    {
        return new WorkPlanCompiler(
            new PackagePlannerService,
            new EstimateDecompositionService,
            new NormativeWorkItemPlannerService(new ProjectDocumentNormativeReferenceExtractor, new EstimatorScopeInferenceService),
            new NormativeContextPinResolver,
        );
    }

    public function test_water_document_is_not_a_source_for_unrelated_unknown_catalog_quantities(): void
    {
        $refs = [['document_id' => 82, 'page_number' => 3]];
        $analysis = (new ConstructionSemanticParser)->parse(['building_type' => 'house'], [[
            'id' => 82, 'filename' => 'water.pdf', 'status' => 'ready', 'quality' => ['level' => 'good'],
            'extracted_text' => 'Водоснабжение', 'document_understanding' => ['role_for_estimation' => 'quantity_source'],
            'facts' => [['fact_type' => 'pipe_length', 'value' => 64, 'unit' => 'm', 'source_ref' => $refs[0]]],
            'quantity_takeoffs' => [['quantity' => 64, 'unit' => 'm', 'source_refs' => $refs,
                'normalized_payload' => ['quantity_key' => 'plumbing.pipe']]],
        ]]);
        self::assertSame($refs, $analysis['document_context']['source_refs']);
        $analysis['evaluation_policy'] = ['mode' => 'universal', 'selected_sections' => ['structures', 'roofing', 'electricity', 'water_supply']];
        $payload = $this->compiler()->compile($analysis, deferNormativePin: true);
        $packages = array_column($payload['local_estimates'], null, 'key');
        foreach (['foundation', 'roof', 'electrical'] as $key) {
            $items = array_merge(...array_column($packages[$key]['sections'], 'work_items'));
            self::assertNotEmpty($items, $key);
            foreach ($items as $item) {
                self::assertNull($item['quantity']);
                self::assertSame([], $item['source_refs'], $key);
            }
        }
        $water = array_merge(...array_column($packages['plumbing']['sections'], 'work_items'));
        $pipes = array_values(array_filter($water, static fn (array $item): bool => ($item['metadata']['quantity_key'] ?? null) === 'plumbing.pipe'));
        self::assertCount(1, $pipes);
        self::assertSame('64', $pipes[0]['quantity']);
        self::assertSame($refs, $pipes[0]['source_refs']);
    }

    #[DataProvider('unresolvedManualDescriptions')]
    public function test_unresolved_manual_semantics_require_clarification_without_invented_work(string $description): void
    {
        $analysis = (new ConstructionSemanticParser)->parse(['building_type' => 'bridge', 'description' => $description], []);
        $analysis['evaluation_policy'] = ['mode' => 'universal', 'selected_sections' => ['structures', 'equipment']];
        $payload = $this->compiler()->compile($analysis, deferNormativePin: true);
        self::assertSame([], $payload['package_plan']['packages']);
        self::assertSame([], $payload['local_estimates']);
        self::assertSame('document_limited', $payload['document_requirements']['coverage']);
        self::assertTrue($payload['document_requirements']['missing_for_full_estimate']);
        self::assertContains('project_scope', array_column($payload['document_requirements']['items'], 'key'));
        self::assertSame($description, $analysis['object']['manual_description']);
    }

    public static function unresolvedManualDescriptions(): iterable
    {
        yield 'stands are not walls' => ['Монтаж стендов'];
        yield 'exhibition stands are not walls' => ['Монтаж выставочных стендов'];
        yield 'unknown technology needs clarification' => ['Требуется уточнить неизвестную технологию'];
    }



    public static function combinedSourceWorkDescriptions(): iterable
    {
        yield 'earthworks and landscaping' => ['Земляные работы и благоустройство территории', ['earthworks']];
        yield 'water and sewerage' => ['Водоснабжение и канализация', ['water_supply', 'sewerage']];
    }

    #[DataProvider('rawManualWorkDescriptions')]
    public function test_raw_manual_work_description_waits_for_composer_semantics(string $description): void
    {
        $analysis = (new ConstructionSemanticParser)->parse(['building_type' => 'bridge', 'description' => $description], []);
        $this->assertNoPreComposerWork($analysis);
        self::assertSame($description, $analysis['object']['manual_description']);
    }

    public static function rawManualWorkDescriptions(): iterable
    {
        foreach ([self::requestedUnknownScopes(), self::multipleExplicitScopes(), self::unknownOptionalSiteRequests(),
            self::independentOptionalSiteRequests(), self::constructiveRequestsWithExcludedSiteWorks(),
            self::explicitScopeExclusions(), self::explicitObjectsWithoutIncidentalScopes(),
            self::nonwarehouseExplicitWorkRequests(), self::combinedSourceWorkDescriptions()] as $index => $cases) {
            foreach ($cases as $name => $case) {
                yield $index.'_'.$name => [$case[0]];
            }
        }
    }

    private function assertNoPreComposerWork(array $analysis): void
    {
        $analysis['evaluation_policy'] = ['mode' => 'universal', 'selected_sections' => [
            'structures', 'finishing', 'roofing', 'earthworks', 'water_supply', 'sewerage', 'heating',
            'ventilation', 'electricity', 'automation', 'technological_pipelines', 'equipment',
        ]];
        $payload = $this->compiler()->compile($analysis, deferNormativePin: true);
        self::assertSame([], $payload['package_plan']['packages']);
        self::assertSame([], $payload['local_estimates']);
        self::assertSame('document_limited', $payload['document_requirements']['coverage']);
        self::assertTrue($payload['document_requirements']['missing_for_full_estimate']);
        self::assertContains('project_scope', array_column($payload['document_requirements']['items'], 'key'));
    }

    #[DataProvider('unknownSelectedSections')]
    public function test_unknown_selected_scope_does_not_create_house_work_or_claim_completeness(array $selected, ?string $profileId = null): void
    {
        $analysis = ['object' => ['building_type' => 'bridge', 'description' => 'Мост через реку'],
            'evaluation_policy' => ['mode' => 'universal', 'profile_id' => $profileId, 'price_policy' => 'catalog', 'selected_sections' => $selected],
            'assumptions' => ['Нужно обследование'], 'missing_inputs' => ['Спецификация опор']];
        $payload = $this->compiler()->compile($analysis, deferNormativePin: true);

        self::assertSame('bridge', $payload['object_profile']['object_type']);
        self::assertSame([], $payload['package_plan']['packages']);
        self::assertSame([], $payload['local_estimates']);
        self::assertSame('document_limited', $payload['document_requirements']['coverage']);
        self::assertTrue($payload['document_requirements']['missing_for_full_estimate']);
        self::assertSame($analysis['assumptions'], $payload['package_plan']['assumptions']);
        self::assertSame($analysis['missing_inputs'], $payload['object_profile']['missing_inputs']);
        self::assertSame($analysis['evaluation_policy'], $payload['evaluation_policy']);
    }

    public static function unknownSelectedSections(): iterable
    {
        yield 'structures' => [['structures']];
        yield 'equipment' => [['equipment']];
        yield 'structures_industrial_profile' => [['structures'], 'industrial'];
        yield 'equipment_industrial_profile' => [['equipment'], 'industrial'];
    }



    public static function requestedUnknownScopes(): iterable
    {
        yield 'rough_finishing' => ['Черновая отделка', 'rough_finishing', 'rough_finishing', 'finishing'];
        yield 'finish_finishing' => ['Чистовая отделка', 'finish_finishing', 'finish_finishing', 'finishing'];
        yield 'foundation' => ['Устройство фундамента', 'foundation', 'foundation', 'structures'];
        yield 'walls' => ['Устройство стен', 'walls', 'walls', 'structures'];
        yield 'slabs' => ['Устройство перекрытий', 'slabs', 'slabs', 'structures'];
        yield 'roof' => ['Устройство кровли', 'roof', 'roof', 'roofing'];
        yield 'openings' => ['Окна и двери', 'openings', 'openings', 'structures'];
        yield 'electrical' => ['Электромонтаж', 'electrical', 'electrical', 'electricity'];
        yield 'plumbing' => ['Водоснабжение', 'plumbing', 'plumbing', 'water_supply'];
        yield 'sewerage' => ['Устройство канализации', 'sewerage', 'sewerage', 'sewerage'];
        yield 'heating' => ['Отопление', 'heating', 'heating', 'heating'];
        yield 'ventilation' => ['Вентиляция', 'ventilation', 'ventilation', 'ventilation'];
        yield 'fire_safety' => ['Пожарная сигнализация', 'fire_safety', 'requested_fire_alarm', 'automation'];
        yield 'stairs' => ['Устройство лестницы', 'stairs', 'stairs', 'structures'];
        yield 'facade' => ['Устройство фасада', 'facade', 'facade', 'structures'];
        yield 'finishing' => ['Штукатурные работы', 'finishing', 'custom-finishing', 'finishing'];
        yield 'earthworks' => ['Земляные работы', 'earthworks', 'earthworks', 'earthworks'];
        yield 'siteworks' => ['Благоустройство территории', 'siteworks', 'siteworks', 'earthworks'];
        yield 'site' => ['Разгрузочная зона', 'site', 'site_preparation', 'earthworks'];
    }



    public static function multipleExplicitScopes(): iterable
    {
        yield 'foundation_and_walls' => ['Устройство фундамента и стен', ['foundation', 'walls'], ['structures']];
        yield 'water_and_sewerage' => ['Водоснабжение и канализация', ['plumbing', 'sewerage'], ['water_supply', 'sewerage']];
        yield 'separate_finishing_phases' => ['Черновая отделка и чистовая отделка', ['rough_finishing', 'finish_finishing'], ['finishing']];
        yield 'specific_overlapping_phrase' => ['Штукатурка стен', ['rough_finishing'], ['finishing', 'structures']];
        yield 'specific_phrase_and_independent_wall' => ['Черновая отделка и стены', ['rough_finishing', 'walls'], ['finishing', 'structures']];
        yield 'paint_wall_surface' => ['Покраска стен', ['finish_finishing'], ['finishing', 'structures']];
        yield 'plaster_facade_surface' => ['Штукатурка фасада', ['custom-finishing'], ['finishing', 'structures']];
        yield 'paint_then_independent_wall' => ['Покраска стен и устройство стен', ['finish_finishing', 'walls'], ['finishing', 'structures']];
        yield 'plaster_then_independent_facade' => ['Штукатурка фасада и устройство фасада', ['facade', 'custom-finishing'], ['finishing', 'structures']];
        yield 'independent_wall_then_paint' => ['Устройство стен; покраска стен', ['walls', 'finish_finishing'], ['finishing', 'structures']];
        yield 'independent_facade_then_plaster' => ['Устройство фасада; штукатурка фасада', ['facade', 'custom-finishing'], ['finishing', 'structures']];
        yield 'window_genitive' => ['Устройство окон', ['openings'], ['structures']];
        yield 'door_genitive' => ['Установка дверей', ['openings'], ['structures']];
        yield 'paint_surface_list_and' => ['Покраска потолков и стен', ['finish_finishing'], ['finishing', 'structures']];
        yield 'paint_facade_list_and' => ['Покраска потолков и фасада', ['finish_finishing'], ['finishing', 'structures']];
        yield 'paint_surface_list_comma' => ['Покраска потолков, стен', ['finish_finishing'], ['finishing', 'structures']];
        yield 'paint_facade_list_comma' => ['Покраска потолков, фасада', ['finish_finishing'], ['finishing', 'structures']];
        yield 'paint_list_then_wall_operation' => ['Покраска потолков и стен и устройство стен', ['finish_finishing', 'walls'], ['finishing', 'structures']];
        yield 'paint_list_then_facade_operation' => ['Покраска потолков, фасада, устройство фасада', ['finish_finishing', 'facade'], ['finishing', 'structures']];
        yield 'paint_then_new_operation_owns_list' => ['Покраска потолков и устройство стен и фасада', ['finish_finishing', 'walls', 'facade'], ['finishing', 'structures']];
        yield 'paint_context_ends_at_other_work' => ['Покраска потолков, электромонтаж, стены', ['finish_finishing', 'electrical', 'walls'], ['finishing', 'electricity', 'structures']];
        yield 'paint_context_ends_at_sentence' => ['Покраска потолков. Стены', ['finish_finishing', 'walls'], ['finishing', 'structures']];
        yield 'finishing_operation_owns_its_surface_list' => ['Укладка плитки на пол и стены', ['finish_finishing'], ['finishing', 'structures']];
        yield 'finishing_operation_then_new_wall_operation' => ['Укладка плитки на пол и устройство стен', ['finish_finishing', 'walls'], ['finishing', 'structures']];
        yield 'surface_gluing_operation_and_list' => ['Оклейка потолков и стен', ['custom-finishing'], ['finishing', 'structures']];
        yield 'surface_gluing_operation_comma_list' => ['Поклейка потолков, стен', ['custom-finishing'], ['finishing', 'structures']];
        yield 'surface_gluing_then_independent_wall' => ['Оклейка потолков и устройство стен', ['walls', 'custom-finishing'], ['finishing', 'structures']];
        yield 'surface_gluing_comma_then_independent_wall' => ['Поклейка потолков, устройство стен', ['custom-finishing', 'walls'], ['finishing', 'structures']];
        yield 'paint_defined_wall_surface' => ['Покраска внутренних стен', ['finish_finishing'], ['finishing', 'structures']];
        yield 'plaster_defined_external_wall_surface' => ['Штукатурка наружных стен', ['custom-finishing'], ['finishing', 'structures']];
        yield 'plaster_defined_bearing_wall_surface' => ['Штукатурка несущих стен', ['custom-finishing'], ['finishing', 'structures']];
        yield 'defined_surface_then_new_wall_operation' => ['Покраска внутренних стен и устройство стен', ['finish_finishing', 'walls'], ['finishing', 'structures']];
        yield 'defined_surface_then_new_facade_operation' => ['Штукатурка наружных стен, устройство фасада', ['custom-finishing', 'facade'], ['finishing', 'structures']];
        yield 'paint_singular_defined_wall_surface' => ['Покраска внутренней стены', ['finish_finishing'], ['finishing', 'structures']];
        yield 'plaster_singular_external_wall_surface' => ['Штукатурка наружной стены', ['custom-finishing'], ['finishing', 'structures']];
        yield 'paint_singular_bearing_wall_surface' => ['Покраска несущей стены', ['finish_finishing'], ['finishing', 'structures']];
        yield 'singular_defined_surface_then_new_wall_operation' => ['Покраска внутренней стены и устройство стен', ['finish_finishing', 'walls'], ['finishing', 'structures']];
        yield 'finishing_does_not_swallow_another_declined_operation' => ['Покраска потолков с устройством стен и фасада', ['finish_finishing', 'walls', 'facade'], ['finishing', 'structures']];
        yield 'finishing_list_does_not_swallow_foundation' => ['Покраска потолков, фундамент', ['finish_finishing', 'foundation'], ['finishing', 'structures']];
        yield 'finishing_list_does_not_swallow_slabs' => ['Покраска потолков, перекрытия', ['finish_finishing', 'slabs'], ['finishing', 'structures']];
    }



    public static function unknownOptionalSiteRequests(): iterable
    {
        yield 'road' => ['Устройство дороги', 'roads'];
        yield 'external_networks' => ['Наружные инженерные сети', 'external_networks'];
    }

    #[DataProvider('excludedOptionalSiteRequests')]
    public function test_excluded_manual_site_request_does_not_return_through_optional_flags(string $description): void
    {
        $analysis = (new ConstructionSemanticParser)->parse(['building_type' => 'bridge', 'description' => $description], []);
        $analysis['evaluation_policy'] = ['mode' => 'universal', 'selected_sections' => ['earthworks']];
        $payload = $this->compiler()->compile($analysis, deferNormativePin: true);
        self::assertSame([], $analysis['detected_structure']['explicit_work_scopes']);
        self::assertSame([], $payload['package_plan']['packages']);
        self::assertSame([], $payload['local_estimates']);
        self::assertSame('document_limited', $payload['document_requirements']['coverage']);
        self::assertSame($description, $analysis['object']['manual_description']);
    }

    public static function excludedOptionalSiteRequests(): iterable
    {
        yield 'roads' => ['Без дорог'];
        yield 'networks' => ['Наружные сети не требуются'];
        yield 'siteworks' => ['Без благоустройства'];
    }



    public static function independentOptionalSiteRequests(): iterable
    {
        yield 'network_after_road_exclusion' => ['Без дорог; наружные инженерные сети', 'external_networks'];
        yield 'road_after_siteworks_exclusion' => ['Без благоустройства; устройство дороги', 'roads'];
        yield 'siteworks_after_network_exclusion' => ['Наружные сети не требуются; благоустройство территории', 'siteworks'];
        yield 'independent_operation_same_sentence' => ['Без дорог и устройство наружных сетей', 'external_networks'];
    }



    public static function constructiveRequestsWithExcludedSiteWorks(): iterable
    {
        yield 'wall_without_roads' => ['Устройство стен без дорог'];
        yield 'wall_after_road_exclusion' => ['Без дорог и устройство стен'];
        yield 'wall_without_siteworks' => ['Устройство стен без благоустройства'];
        yield 'wall_after_network_exclusion' => ['Наружные сети не требуются и устройство стен'];
    }

    #[DataProvider('unsupportedFireSystems')]
    public function test_other_fire_or_security_systems_do_not_become_a_fire_alarm(string $description): void
    {
        $analysis = (new ConstructionSemanticParser)->parse(['building_type' => 'bridge', 'description' => $description], []);
        $analysis['evaluation_policy'] = ['mode' => 'universal', 'selected_sections' => ['automation']];
        $payload = $this->compiler()->compile($analysis, deferNormativePin: true);

        self::assertSame($description, $analysis['object']['manual_description']);
        self::assertSame([], $payload['package_plan']['packages']);
        self::assertSame([], $payload['local_estimates']);
        self::assertSame('document_limited', $payload['document_requirements']['coverage']);
        self::assertNotEmpty($payload['document_requirements']['items']);
    }

    public static function unsupportedFireSystems(): iterable
    {
        yield 'fire_extinguishing' => ['Монтаж пожаротушения'];
        yield 'security_alarm' => ['Монтаж охранной сигнализации'];
        yield 'fire_notification' => ['Пожарное оповещение'];
    }

    public function test_standard_warehouse_and_industrial_fire_packages_do_not_become_explicit_automation_requests(): void
    {
        foreach (['warehouse', 'industrial'] as $type) {
            $analysis = (new ConstructionSemanticParser)->parse(['building_type' => $type], []);
            $analysis['evaluation_policy'] = ['mode' => 'universal', 'selected_sections' => ['automation']];
            $payload = $this->compiler()->compile($analysis, deferNormativePin: true);
            self::assertNotContains('fire_safety', array_column($payload['package_plan']['packages'], 'key'), $type);
            self::assertSame($type === 'industrial' ? ['automation'] : [], array_column($payload['local_estimates'], 'key'), $type);
        }
    }

    public function test_raw_manual_fire_alarm_description_does_not_add_an_automation_request(): void
    {
        foreach (['warehouse', 'industrial', 'house'] as $type) {
            $analysis = (new ConstructionSemanticParser)->parse(['building_type' => $type, 'description' => 'Пожарная сигнализация'], []);
            $analysis['evaluation_policy'] = ['mode' => 'universal', 'selected_sections' => ['automation']];
            $payload = $this->compiler()->compile($analysis, deferNormativePin: true);
            $keys = array_column($payload['local_estimates'], 'key');
            self::assertNotContains('requested_fire_alarm', $keys, $type);
            self::assertNotContains('fire_safety', $keys, $type);
            self::assertSame($type === 'industrial' ? ['automation'] : [], $keys, $type);
        }
    }



    public static function explicitScopeExclusions(): iterable
    {
        yield 'without_walls' => ['Устройство фундамента без стен', ['foundation']];
        yield 'walls_not_required' => ['Устройство фундамента, стены не требуются', ['foundation']];
        yield 'foundation_not_required' => ['Фундамент не требуется, устройство стен', ['walls']];
        yield 'ambiguous_postfix_list_not_required' => ['Устройство фундамента и стены не требуются', []];
        yield 'conjunct_foundation_not_required' => ['Фундамент не требуется и устройство стен', ['walls']];
        yield 'without_foundation_before_independent_wall_work' => ['Без фундамента и устройство стен', ['walls']];
        yield 'prefix_not_required_before_independent_wall_work' => ['Не требуется устройство фундамента и устройство стен', ['walls']];
        yield 'without_compound_scope' => ['Устройство фундамента без стен и перекрытий', ['foundation']];
        yield 'without_list_before_independent_foundation' => ['Без стен и перекрытий, устройство фундамента', ['foundation']];
        yield 'prefix_not_required_list' => ['Не требуется устройство стен и перекрытий, фундамент', ['foundation']];
        yield 'without_genitive_construction_operations' => ['Без монтажа кровли и устройства стен', []];
        yield 'without_genitive_finishing_operations' => ['Без укладки ламината и оклейки обоями', []];
        yield 'without_roof_then_independent_wall_operation' => ['Без монтажа кровли и устройство стен', ['walls']];
        yield 'without_walls_then_independent_roof_operation' => ['Без устройства стен и монтаж металлочерепицы', ['roof']];
        yield 'context_before_prefix' => ['На объекте не требуется устройство стен', []];
        yield 'context_before_foundation_prefix' => ['Для моста не требуется устройство фундамента', []];
        yield 'context_before_operation_prefix' => ['Для здания не выполнять монтаж металлочерепицы', []];
        yield 'postfix_noun_list' => ['Стены и перекрытия не требуются', []];
        yield 'postfix_operation_list' => ['Устройство стен и перекрытий не требуется', []];
        yield 'independent_work_before_postfix_list' => ['Устройство фундамента, стены и перекрытия не требуются', ['foundation']];
        yield 'do_not_build_walls' => ['Не выполнять устройство стен, устройство фундамента', ['foundation']];
        yield 'no_wall_required' => ['Не требуется устройство стен, фундамент', ['foundation']];
        yield 'no_work_required' => ['Стены не требуются', []];
    }



    public static function explicitObjectsWithoutIncidentalScopes(): iterable
    {
        yield 'external_walls' => ['Устройство наружных стен', ['walls']];
        yield 'roof_insulation' => ['Утепление кровли', ['roof']];
        yield 'roof_material' => ['Кровля из сэндвич-панелей', ['roof']];
        yield 'roof_tiles' => ['Монтаж кровли из металлочерепицы', ['roof']];
        yield 'slab_material' => ['Монолитная плита из газобетона', ['slabs']];
        yield 'water_supply_valve' => ['Приточный клапан водопровода', ['plumbing']];
        yield 'stair_landing' => ['Площадка лестницы', ['stairs']];
        yield 'material_only' => ['Сэндвич-панели и газобетон', []];
        yield 'flooring_materials_only' => ['Ламинат, плитка и обои', []];
        yield 'unspecified_insulation' => ['Утепление', []];
        yield 'unspecified_valve' => ['Приточный клапан', []];
        yield 'site_work' => ['Благоустройство территории', ['siteworks']];
        yield 'explicit_external_area' => ['Наружная площадка', ['site_preparation']];
        yield 'facade_work' => ['Устройство фасада', ['facade']];
        yield 'roof_work' => ['Устройство кровли', ['roof']];
        yield 'wall_work' => ['Устройство стен', ['walls']];
        yield 'explicit_flooring_operation' => ['Укладка ламината', ['finish_finishing']];
        yield 'explicit_roof_material_operation' => ['Монтаж металлочерепицы', ['roof']];
        yield 'explicit_foundation_preparation' => ['Устройство бетонной подготовки', ['foundation']];
        yield 'explicit_equipment_base' => ['Устройство основания под оборудование', ['foundation']];
        yield 'explicit_tile_operation' => ['Укладка плитки', ['finish_finishing']];
        yield 'explicit_wallpaper_operation' => ['Оклейка обоями', ['finish_finishing']];
        yield 'explicit_wall_material_operation' => ['Монтаж газобетона', ['walls']];
        yield 'explicit_panel_operation' => ['Монтаж сэндвич-панелей', ['facade']];
        yield 'explicit_area_operation' => ['Устройство площадки', ['site_preparation']];
        yield 'explicit_access_operation' => ['Устройство подъезда', ['site_preparation', 'roads']];
        yield 'explicit_driveway_operation' => ['Устройство проезда', ['site_preparation', 'roads']];
        yield 'separate_roof_and_wall_operations' => ['Монтаж металлочерепицы и устройство стен', ['walls', 'roof']];
        yield 'preparation_belongs_to_stated_roof' => ['Устройство бетонной подготовки кровли', ['roof']];
        yield 'block_material_belongs_to_stated_slab' => ['Монтаж газобетона на перекрытии', ['slabs']];
        yield 'generic_equipment_is_not_wallpaper' => ['Монтаж оборудования', []];
        yield 'ambiguous_block_material_adjective' => ['Монтаж газобетонных блоков', []];
        yield 'ambiguous_single_block_adjective' => ['Монтаж газобетонного блока', []];
        yield 'ambiguous_panel_material_adjective' => ['Монтаж ламинатных панелей', []];
        yield 'explicit_wall_with_material_adjective' => ['Устройство стен из газобетонных блоков', ['walls']];
        yield 'explicit_roof_with_material_adjective' => ['Монтаж кровли из металлочерепичных листов', ['roof']];
        yield 'laminator_is_equipment' => ['Установка ламинатора', []];
        yield 'tile_cutter_is_equipment' => ['Монтаж плиткореза', []];
        yield 'entrance_intercom_is_equipment' => ['Монтаж подъездного домофона', []];
        yield 'excluded_operation_cannot_authorize_another_clause' => ['Не выполнять монтаж газобетона; монтаж газобетона на перекрытии', ['slabs']];
        yield 'postfixed_exclusion_cannot_authorize_another_clause' => ['Монтаж металлочерепицы не требуется; монтаж металлочерепицы на стене', ['walls']];
        foreach (['наружных', 'приточного', 'клапана', 'кладочных', 'утепления', 'света', 'облицовки'] as $hint) {
            yield 'descriptor_operation_'.$hint => ['Устройство '.$hint, []];
        }
    }

    public function test_raw_manual_road_access_nouns_wait_for_composer_semantics(): void
    {
        foreach (['Дорога', 'Устройство подъезда', 'Устройство проезда'] as $description) {
            $analysis = (new ConstructionSemanticParser)->parse(['building_type' => 'bridge', 'description' => $description], []);
            $this->assertNoPreComposerWork($analysis);
        }
    }

    public function test_raw_manual_landscaping_waits_for_composer_semantics(): void
    {
        $analysis = (new ConstructionSemanticParser)->parse(['building_type' => 'bridge', 'description' => 'Благоустройство территории'], []);
        $analysis['evaluation_policy'] = ['mode' => 'universal', 'selected_sections' => ['earthworks']];
        $this->assertNoPreComposerWork($analysis);
    }

    public function test_known_profiles_keep_separate_requested_network_and_road_packages_when_landscaping_is_narrowed(): void
    {
        foreach (['warehouse', 'industrial'] as $type) {
            $takeoffs = [];
            foreach (['siteworks.area' => ['50', 'м2'], 'networks.external' => ['120', 'м'], 'warehouse.roads' => ['40', 'м2']] as $key => [$value, $unit]) {
                $takeoffs[] = ['quantity' => $value, 'unit' => $unit, 'source_refs' => [['document_id' => 82]],
                    'normalized_payload' => ['quantity_key' => $key]];
            }
            $analysis = (new ConstructionSemanticParser)->parse(['building_type' => $type,
                'description' => 'Благоустройство территории; устройство подъезда; наружные инженерные сети'], [[
                'id' => 82, 'filename' => 'site-work-volumes.pdf', 'status' => 'ready', 'quality' => ['level' => 'good'],
                'document_understanding' => ['role_for_estimation' => 'quantity_source'], 'quantity_takeoffs' => $takeoffs,
            ]]);
            $payload = $this->compiler()->compile($analysis, deferNormativePin: true);
            $packages = array_column($payload['package_plan']['packages'], null, 'key');
            $estimates = array_column($payload['local_estimates'], null, 'key');
            foreach (['siteworks', 'roads', 'external_networks'] as $key) {
                self::assertArrayHasKey($key, $packages, $type);
                self::assertArrayHasKey($key, $estimates, $type);
            }
            $keys = static fn (string $package): array => array_column(array_column($estimates[$package]['sections'][0]['work_items'], 'metadata'), 'quantity_key');
            self::assertSame(['siteworks.area'], $keys('siteworks'), $type);
            self::assertSame(['networks.external'], $keys('external_networks'), $type);
            if ($type === 'warehouse') {
                self::assertSame(['warehouse.roads'], $keys('roads'));
            } else {
                self::assertSame([], $keys('roads'));
            }
        }
    }

    #[DataProvider('documentOptionalSitePackages')]
    public function test_document_site_package_has_one_owner_and_preserves_independent_source_rows(string $description, string $quantityKey, string $package, bool $applicable): void
    {
        $documents = [];
        foreach ([82 => 'segment-a', 83 => 'segment-b'] as $documentId => $entity) {
            $key = $quantityKey.'.'.$entity;
            $source = [['document_id' => $documentId, 'entity_id' => $entity]];
            $documents[] = ['id' => $documentId, 'filename' => $entity.'.pdf', 'status' => 'ready', 'quality' => ['level' => 'good'],
                'document_understanding' => ['role_for_estimation' => 'quantity_source'],
                'quantity_takeoffs' => [['quantity' => 12, 'unit' => 'м', 'source_refs' => $source,
                    'normalized_payload' => ['quantity_key' => $key]]],
                'scope_inferences' => [['scope_type' => 'site', 'inference_type' => 'specification_takeoff',
                    'title' => 'Работа по ведомости', 'review_required' => false, 'source_refs' => $source,
                    'normalized_payload' => ['quantity_key' => $key, 'entity_id' => $entity, 'quantity_value' => 12, 'unit' => 'м']]],
            ];
        }
        $analysis = (new ConstructionSemanticParser)->parse(['building_type' => 'bridge', 'description' => $description], $documents);
        $payload = $this->compiler()->compile($analysis, deferNormativePin: true);

        self::assertSame([$package], array_column($payload['package_plan']['packages'], 'key'));
        self::assertSame([$package], array_column($payload['local_estimates'], 'key'));
        $items = array_merge(...array_column($payload['local_estimates'][0]['sections'], 'work_items'));
        if (! $applicable) {
                self::assertSame([], $items);

            return;
        }
        $documentItems = array_values(array_filter($items, static fn (array $item): bool => ($item['metadata']['generation_source'] ?? null) === 'scope_inference'));
        self::assertCount(2, $documentItems);
        self::assertSame(['12', '12'], array_column($documentItems, 'quantity'));
        self::assertSame([82, 83], array_map(static fn (array $item): int => $item['source_refs'][0]['document_id'], $documentItems));
        self::assertSame(['segment-a', 'segment-b'], array_map(static fn (array $item): string => $item['metadata']['scope_inference']['normalized_payload']['entity_id'], $documentItems));
    }

    public static function documentOptionalSitePackages(): iterable
    {
        yield 'networks' => ['Наружные инженерные сети', 'networks.external', 'external_networks', true];
        yield 'roads' => ['Устройство дороги', 'warehouse.roads', 'roads', false];
        yield 'document_network_rows_with_excluded_manual_request' => ['Наружные сети не требуются', 'networks.external', 'external_networks', true];
        yield 'document_road_scope_with_excluded_manual_request' => ['Без дорог', 'warehouse.roads', 'roads', false];
    }

    public function test_raw_manual_site_and_landscaping_requests_do_not_create_work_but_document_quantity_survives(): void
    {
        foreach (['bridge', 'warehouse', 'industrial'] as $type) {
            $analysis = (new ConstructionSemanticParser)->parse(['building_type' => $type,
                'description' => 'Наружная площадка и благоустройство территории'], []);
            $analysis['evaluation_policy'] = ['mode' => 'universal', 'selected_sections' => ['earthworks']];
            if ($type === 'bridge') {
                $this->assertNoPreComposerWork($analysis);
            } else {
                $baseline = (new ConstructionSemanticParser)->parse(['building_type' => $type], []);
                $baseline['evaluation_policy'] = $analysis['evaluation_policy'];
                $withDescription = $this->compiler()->compile($analysis, deferNormativePin: true);
                $withoutDescription = $this->compiler()->compile($baseline, deferNormativePin: true);
                self::assertSame(array_column($withoutDescription['package_plan']['packages'], 'key'),
                    array_column($withDescription['package_plan']['packages'], 'key'), $type);
                self::assertSame(array_column($withoutDescription['local_estimates'], 'key'),
                    array_column($withDescription['local_estimates'], 'key'), $type);
            }

            $documentAnalysis = (new ConstructionSemanticParser)->parse(['building_type' => $type, 'description' => 'Наружная площадка'], [[
                'id' => 82, 'filename' => 'landscaping-volumes.pdf', 'status' => 'ready', 'quality' => ['level' => 'good'],
                'document_understanding' => ['role_for_estimation' => 'quantity_source'],
                'quantity_takeoffs' => [['quantity' => '50', 'unit' => 'м2', 'source_refs' => [['document_id' => 82]],
                    'normalized_payload' => ['quantity_key' => 'siteworks.area']]],
            ]]);
            $documentAnalysis['evaluation_policy'] = ['mode' => 'universal', 'selected_sections' => ['earthworks']];
            $documentItems = array_column($this->compiler()->compile($documentAnalysis, deferNormativePin: true)['local_estimates'], null, 'key');
            self::assertArrayHasKey('siteworks', $documentItems, $type);
            self::assertSame(['siteworks.area'], array_column(array_column($documentItems['siteworks']['sections'][0]['work_items'], 'metadata'), 'quantity_key'), $type);
        }
    }

    public function test_trusted_document_multiple_work_requests_keep_source_context_without_inventing_work(): void
    {
        $analysis = (new ConstructionSemanticParser)->parse(['building_type' => 'bridge'], [[
            'id' => 82, 'source_version' => 'source-v1', 'status' => 'ready', 'quality' => ['level' => 'good'],
            'extracted_text' => 'Устройство фундамента и стен', 'document_understanding' => ['role_for_estimation' => 'quantity_source'],
        ]]);
        $analysis['evaluation_policy'] = ['mode' => 'universal', 'selected_sections' => ['structures']];
        self::assertSame([], $analysis['detected_structure']['explicit_work_scopes']);
        $rawScopes = $analysis['source_documents'][0]['scopes'];
        $payload = $this->compiler()->compile($analysis, deferNormativePin: true);
        self::assertSame([], $analysis['detected_structure']['explicit_work_scopes']);
        self::assertSame([], $payload['package_plan']['packages']);
        self::assertSame([], $payload['local_estimates']);
        self::assertSame([82], array_column($analysis['source_documents'], 'id'));
        self::assertSame('source-v1', $analysis['source_documents'][0]['source_version']);
        self::assertSame($rawScopes, $analysis['source_documents'][0]['scopes']);
    }

    #[DataProvider('documentOptionalScopeProvenance')]
    public function test_document_optional_work_request_keeps_its_source_provenance_without_creating_work(string $text, string $package): void
    {
        $analysis = (new ConstructionSemanticParser)->parse(['building_type' => 'bridge'], [[
            'id' => 82, 'source_version' => 'source-v1', 'status' => 'ready', 'quality' => ['level' => 'good'],
            'extracted_text' => $text, 'document_understanding' => ['role_for_estimation' => 'quantity_source'],
        ]]);
        $analysis['evaluation_policy'] = ['mode' => 'universal', 'selected_sections' => ['earthworks']];
        $payload = $this->compiler()->compile($analysis, deferNormativePin: true);
        self::assertSame([], $payload['package_plan']['packages']);
        self::assertSame([], $payload['local_estimates']);
        self::assertSame([82], array_column($analysis['source_documents'], 'id'));
        self::assertSame('source-v1', $analysis['source_documents'][0]['source_version']);
        self::assertNotEmpty($analysis['source_documents'][0]['scopes']['items']);
    }

    public static function documentOptionalScopeProvenance(): iterable
    {
        yield 'networks' => ['Наружные сети', 'external_networks'];
        yield 'roads' => ['Дороги и площадки', 'roads'];
    }

    #[DataProvider('documentLightingRequests')]
    public function test_document_lighting_request_does_not_invent_a_power_distribution_system(string $text): void
    {
        $analysis = (new ConstructionSemanticParser)->parse(['building_type' => 'bridge'], [[
            'id' => 82, 'source_version' => 'source-v1', 'status' => 'ready', 'quality' => ['level' => 'good'],
            'extracted_text' => $text, 'document_understanding' => ['role_for_estimation' => 'quantity_source'],
        ]]);
        $analysis['evaluation_policy'] = ['mode' => 'universal', 'selected_sections' => ['electricity']];
        $payload = $this->compiler()->compile($analysis, deferNormativePin: true);
        $items = [];
        foreach ($payload['local_estimates'] as $estimate) {
            array_push($items, ...array_merge(...array_column($estimate['sections'], 'work_items')));
        }
        self::assertSame([], $items);
        $keys = array_column(array_column($items, 'metadata'), 'quantity_key');
        self::assertNotContains('electrical.main_cable', $keys);
        self::assertNotContains('electrical.panel', $keys);
    }

    public static function documentLightingRequests(): iterable
    {
        yield 'lighting' => ['Освещение'];
        yield 'fixtures' => ['Монтаж светильников'];
    }



    public static function nonwarehouseExplicitWorkRequests(): iterable
    {
        yield 'alarm' => ['Пожарная сигнализация', 'automation'];
        yield 'roads' => ['Устройство дороги', 'earthworks'];
    }

    public function test_equal_source_scope_titles_from_independent_documents_remain_in_source_context(): void
    {
        $documents = [];
        foreach ([82, 83] as $documentId) {
            $documents[] = ['id' => $documentId, 'source_version' => 'source-v1', 'status' => 'ready',
                'quality' => ['level' => 'good'], 'extracted_text' => 'Устройство фундамента и стен',
                'document_understanding' => ['role_for_estimation' => 'quantity_source']];
        }
        $analysis = (new ConstructionSemanticParser)->parse(['building_type' => 'bridge'], $documents);
        $analysis['evaluation_policy'] = ['mode' => 'universal', 'selected_sections' => ['structures']];
        $payload = $this->compiler()->compile($analysis, deferNormativePin: true);
        self::assertSame([], $payload['package_plan']['packages']);
        self::assertSame([], $payload['local_estimates']);
        self::assertSame([82, 83], array_column($analysis['source_documents'], 'id'));
        self::assertSame(['source-v1', 'source-v1'], array_column($analysis['source_documents'], 'source_version'));
    }

    public function test_trusted_document_scope_without_a_number_preserves_source_context_only(): void
    {
        $analysis = (new ConstructionSemanticParser)->parse(['building_type' => 'bridge'], [[
            'id' => 82, 'source_version' => 'source-v1', 'filename' => 'foundation.pdf', 'status' => 'ready',
            'quality' => ['level' => 'good'], 'extracted_text' => 'Устройство фундамента',
            'document_understanding' => ['role_for_estimation' => 'quantity_source'],
        ]]);
        $analysis['evaluation_policy'] = ['mode' => 'universal', 'selected_sections' => ['structures']];
        self::assertSame([], $analysis['detected_structure']['explicit_work_scopes']);
        self::assertSame(['foundation'], array_column($analysis['source_documents'][0]['scopes']['items'], 'scope_type'));
        $payload = $this->compiler()->compile($analysis, deferNormativePin: true);
        self::assertSame([], $payload['package_plan']['packages']);
        self::assertSame([], $payload['local_estimates']);
        self::assertSame('document_limited', $payload['document_requirements']['coverage']);
        self::assertTrue($payload['document_requirements']['missing_for_full_estimate']);
        self::assertSame(82, $analysis['source_documents'][0]['id']);
        self::assertSame('source-v1', $analysis['source_documents'][0]['source_version']);
        self::assertSame('foundation.pdf', $analysis['source_documents'][0]['filename']);
        self::assertContains('foundation', array_column($analysis['source_documents'][0]['scopes']['items'], 'scope_type'));
    }

    #[DataProvider('excludedDocumentScopeOutlines')]
    public function test_document_scope_text_does_not_create_work_regardless_of_trust_status(string $text, string $status, string $quality, string $role, array $expected = []): void
    {
        $analysis = (new ConstructionSemanticParser)->parse(['building_type' => 'bridge'], [[
            'id' => 82, 'source_version' => 'source-v1', 'status' => $status, 'quality' => ['level' => $quality],
            'extracted_text' => $text, 'document_understanding' => ['role_for_estimation' => $role],
        ]]);
        $rawScopes = $analysis['source_documents'][0]['scopes'];
        self::assertContains('foundation', array_column($rawScopes['items'], 'scope_type'));
        $analysis['evaluation_policy'] = ['mode' => 'universal', 'selected_sections' => ['structures']];
        $payload = $this->compiler()->compile($analysis, deferNormativePin: true);
        self::assertSame([], array_column($payload['package_plan']['packages'], 'key'));
        self::assertSame([], $payload['local_estimates']);
        self::assertSame('document_limited', $payload['document_requirements']['coverage']);
        foreach ($expected as $scope) {
            self::assertContains($scope, array_column($rawScopes['items'], 'scope_type'));
        }
        self::assertSame($rawScopes, $analysis['source_documents'][0]['scopes']);
    }

    public static function excludedDocumentScopeOutlines(): iterable
    {
        yield 'reference_only' => ['Устройство фундамента', 'ready', 'good', 'reference_estimate'];
        yield 'no_role_context' => ['Устройство фундамента', 'ready', 'good', ''];
        yield 'ignored' => ['Устройство фундамента', 'ignored', 'good', 'quantity_source'];
        yield 'failed' => ['Устройство фундамента', 'failed', 'good', 'quantity_source'];
        yield 'low_quality' => ['Устройство фундамента', 'ready', 'poor', 'quantity_source'];
        yield 'prefix_exclusion' => ['Без фундамента', 'ready', 'good', 'quantity_source'];
        yield 'postfix_exclusion' => ['Фундамент не требуется', 'ready', 'good', 'quantity_source'];
        yield 'excluded_scope_with_another_positive_scope' => ['Без фундамента, стены', 'ready', 'good', 'quantity_source', ['walls']];
    }

    #[DataProvider('canonicalDocumentScopeExclusions')]
    public function test_canonical_document_scope_title_does_not_erase_the_original_exclusion(string $text, string $scope): void
    {
        $analysis = (new ConstructionSemanticParser)->parse(['building_type' => 'bridge'], [[
            'id' => 82, 'status' => 'ready', 'quality' => ['level' => 'good'], 'extracted_text' => $text,
            'document_understanding' => ['role_for_estimation' => 'quantity_source'],
        ]]);
        $rawScopes = $analysis['source_documents'][0]['scopes'];
        self::assertSame([$scope], array_column($rawScopes['items'], 'scope_type'));
        self::assertSame([], $this->compiler()->compile($analysis, deferNormativePin: true)['package_plan']['packages']);
        self::assertSame($rawScopes, $analysis['source_documents'][0]['scopes']);
    }

    public static function canonicalDocumentScopeExclusions(): iterable
    {
        yield 'fire' => ['Без пожарной сигнализации', 'fire_safety'];
        yield 'water' => ['Водоснабжение не требуется', 'plumbing'];
        yield 'lighting' => ['Освещение не требуется', 'electrical'];
    }

    public function test_document_scope_exclusion_keeps_an_independent_positive_document_clause(): void
    {
        $analysis = (new ConstructionSemanticParser)->parse(['building_type' => 'bridge'], [[
            'id' => 82, 'status' => 'ready', 'quality' => ['level' => 'good'],
            'extracted_text' => "Без пожарной сигнализации\nУстройство фундамента",
            'document_understanding' => ['role_for_estimation' => 'quantity_source'],
        ]]);
        $analysis['evaluation_policy'] = ['mode' => 'universal', 'selected_sections' => ['structures', 'automation']];
        $payload = $this->compiler()->compile($analysis, deferNormativePin: true);
        self::assertSame([], array_column($payload['package_plan']['packages'], 'key'));
        self::assertSame(['fire_safety', 'foundation'], array_column($analysis['source_documents'][0]['scopes']['items'], 'scope_type'));
        self::assertSame([], $payload['local_estimates']);
        self::assertSame('document_limited', $payload['document_requirements']['coverage']);
    }

    #[DataProvider('documentSiteScopeOnlyCases')]
    public function test_document_site_scope_text_keeps_source_context_without_creating_work(string $text, string $status = 'ready', string $role = 'quantity_source'): void
    {
        $analysis = (new ConstructionSemanticParser)->parse(['building_type' => 'bridge'], [[
            'id' => 82, 'status' => $status, 'quality' => ['level' => 'good'], 'extracted_text' => $text,
            'document_understanding' => ['role_for_estimation' => $role],
        ]]);
        $analysis['evaluation_policy'] = ['mode' => 'universal', 'selected_sections' => ['earthworks']];
        $payload = $this->compiler()->compile($analysis, deferNormativePin: true);
        self::assertSame([], array_column($payload['package_plan']['packages'], 'key'));
        self::assertSame([], $payload['local_estimates']);
        self::assertSame(82, $analysis['source_documents'][0]['id']);
        self::assertSame($status, $analysis['source_documents'][0]['status']);
        self::assertNotEmpty($analysis['source_documents'][0]['scopes']['items']);
    }

    public static function documentSiteScopeOnlyCases(): iterable
    {
        yield 'earthworks' => ['Земляные работы'];
        yield 'landscaping' => ['Благоустройство территории'];
        yield 'excluded_landscaping' => ['Без благоустройства'];
        yield 'excluded_networks' => ['Наружные сети не требуются'];
        yield 'ignored_landscaping' => ['Благоустройство территории', 'ignored'];
        yield 'reference_only_landscaping' => ['Благоустройство территории', 'ready', 'reference_estimate'];
        yield 'excluded_landscaping_keeps_positive_networks' => ["Без благоустройства\nНаружные сети"];
    }

    public function test_document_text_exclusion_does_not_erase_an_independent_document_quantity(): void
    {
        $analysis = (new ConstructionSemanticParser)->parse(['building_type' => 'bridge'], [[
            'id' => 82, 'status' => 'ready', 'quality' => ['level' => 'good'], 'extracted_text' => 'Без благоустройства',
            'document_understanding' => ['role_for_estimation' => 'quantity_source'],
            'quantity_takeoffs' => [['quantity' => 50, 'unit' => 'м2', 'source_refs' => [['document_id' => 82]],
                'normalized_payload' => ['quantity_key' => 'siteworks.area']]],
        ]]);
        $analysis['evaluation_policy'] = ['mode' => 'universal', 'selected_sections' => ['earthworks']];
        $payload = $this->compiler()->compile($analysis, deferNormativePin: true);
        self::assertSame(['siteworks'], array_column($payload['package_plan']['packages'], 'key'));
        $items = $payload['local_estimates'][0]['sections'][0]['work_items'];
        self::assertSame(['siteworks.area'], array_column(array_column($items, 'metadata'), 'quantity_key'));
        self::assertSame('50', $items[0]['quantity']);
        self::assertSame(82, $items[0]['source_refs'][0]['document_id']);
    }

    public function test_document_only_material_row_keeps_its_legacy_scope_and_source_quantity(): void
    {
        $analysis = (new ConstructionSemanticParser)->parse(['building_type' => 'bridge'], [[
            'id' => 82, 'filename' => 'floor-statement.pdf', 'status' => 'ready', 'quality' => ['level' => 'good'],
            'extracted_text' => 'Ламинат', 'document_understanding' => ['role_for_estimation' => 'quantity_source'],
            'quantity_takeoffs' => [['quantity' => 12, 'unit' => 'м2', 'source_refs' => [['document_id' => 82]],
                'normalized_payload' => ['quantity_key' => 'finish.floor']]],
            'scope_inferences' => [['scope_type' => 'finishing', 'inference_type' => 'specification_takeoff',
                'title' => 'Ламинат по ведомости', 'review_required' => false, 'source_refs' => [['document_id' => 82]],
                'normalized_payload' => ['quantity_key' => 'finish.floor', 'quantity_value' => 12, 'unit' => 'м2']]],
        ]]);
        $analysis['evaluation_policy'] = ['mode' => 'universal', 'selected_sections' => ['finishing']];
        self::assertSame('finish_finishing', $analysis['source_documents'][0]['scopes']['items'][0]['scope_type']);
        self::assertSame([], $analysis['detected_structure']['explicit_work_scopes']);
        $payload = $this->compiler()->compile($analysis, deferNormativePin: true);
        self::assertSame(['custom-finishing'], array_column($payload['local_estimates'], 'key'));
        $items = $payload['local_estimates'][0]['sections'][0]['work_items'];
        self::assertCount(1, $items);
        self::assertSame('12', $items[0]['quantity']);
        self::assertSame(82, $items[0]['source_refs'][0]['document_id']);
    }

    #[DataProvider('oppositeFinishingPhases')]
    public function test_raw_manual_finishing_phase_does_not_add_work_beside_document_quantity(string $description, string $quantityKey, string $requestedPackage): void
    {
        $analysis = (new ConstructionSemanticParser)->parse(['description' => $description], [[
            'id' => 82, 'filename' => 'work-statement.pdf', 'status' => 'ready', 'quality' => ['level' => 'good'],
            'extracted_text' => 'Ведомость объёмов', 'document_understanding' => ['role_for_estimation' => 'quantity_source'],
            'quantity_takeoffs' => [['quantity' => 12, 'unit' => 'м2', 'source_refs' => [['document_id' => 82]],
                'normalized_payload' => ['quantity_key' => $quantityKey]]],
            'scope_inferences' => [['scope_type' => 'finishing', 'inference_type' => 'specification_takeoff',
                'title' => 'Работа по ведомости', 'review_required' => false, 'source_refs' => [['document_id' => 82]],
                'normalized_payload' => ['quantity_key' => $quantityKey, 'quantity_value' => 12, 'unit' => 'м2']]],
        ]]);
        $analysis['evaluation_policy'] = ['mode' => 'universal', 'selected_sections' => ['finishing']];
        $payload = $this->compiler()->compile($analysis, deferNormativePin: true);

        self::assertSame(['custom-finishing'], array_column($payload['package_plan']['packages'], 'key'));
        self::assertSame(['custom-finishing'], array_column($payload['local_estimates'], 'key'));
        self::assertNotContains($requestedPackage, array_column($payload['local_estimates'], 'key'));
        $items = array_merge(...array_map(static fn (array $estimate): array => array_merge(...array_column($estimate['sections'], 'work_items')), $payload['local_estimates']));
        $documentItems = array_values(array_filter($items, static fn (array $item): bool => ($item['metadata']['quantity_key'] ?? null) === $quantityKey));
        self::assertSame(12, array_sum(array_column($documentItems, 'quantity')));
        self::assertCount(1, $documentItems);
        self::assertSame('12', $documentItems[0]['quantity']);
        self::assertSame(82, $documentItems[0]['source_refs'][0]['document_id']);
        self::assertSame('document_limited', $payload['document_requirements']['coverage']);
    }

    public static function oppositeFinishingPhases(): iterable
    {
        yield 'finish_does_not_hide_rough' => ['Черновая отделка', 'finish.floor', 'rough_finishing'];
        yield 'rough_does_not_hide_finish' => ['Чистовая отделка', 'rough.floor', 'finish_finishing'];
    }

    public function test_equal_looking_document_work_for_independent_entities_is_kept_in_its_document_package(): void
    {
        $inferences = [];
        foreach (['room-a', 'room-b'] as $entity) {
            $inferences[] = ['scope_type' => 'finishing', 'inference_type' => 'specification_takeoff',
                'title' => 'Окраска стен', 'review_required' => false,
                'source_refs' => [['document_id' => 82, 'entity_id' => $entity]],
                'normalized_payload' => ['quantity_key' => 'finish.paint.'.$entity, 'entity_id' => $entity, 'quantity_value' => 12, 'unit' => 'м2']];
        }
        $analysis = (new ConstructionSemanticParser)->parse(['description' => 'Черновая отделка'], [[
            'id' => 82, 'filename' => 'walls.pdf', 'status' => 'ready', 'quality' => ['level' => 'good'],
            'extracted_text' => 'Ведомость объёмов', 'document_understanding' => ['role_for_estimation' => 'quantity_source'],
            'scope_inferences' => $inferences,
        ]]);
        $analysis['evaluation_policy'] = ['mode' => 'universal', 'selected_sections' => ['finishing']];
        $payload = $this->compiler()->compile($analysis, deferNormativePin: true);

        self::assertSame(['custom-finishing'], array_column($payload['local_estimates'], 'key'));
        $documentItems = array_merge(...array_column($payload['local_estimates'][0]['sections'], 'work_items'));
        self::assertCount(2, $documentItems);
        self::assertSame(['12', '12'], array_column($documentItems, 'quantity'));
        self::assertSame(['room-a', 'room-b'], array_map(static fn (array $item): string => $item['metadata']['scope_inference']['normalized_payload']['entity_id'], $documentItems));
    }

    public function test_raw_manual_foundation_description_waits_for_composer_semantics(): void
    {
        $analysis = (new ConstructionSemanticParser)->parse(['building_type' => 'bridge', 'description' => 'Устройство фундамента'], []);
        $analysis['evaluation_policy'] = ['mode' => 'universal', 'selected_sections' => ['structures']];
        $payload = $this->compiler()->compile($analysis, deferNormativePin: true);

        self::assertSame('bridge', $payload['object_profile']['object_type']);
        $this->assertNoPreComposerWork($analysis);
    }

    #[DataProvider('explicitRequestEvidenceBranches')]
    public function test_document_quantity_survives_without_raw_manual_scope_inference(string $description, string $quantityKey, string $context, string $expectedProfile, array $expectedPackages, string $selected): void
    {
        $analysis = (new ConstructionSemanticParser)->parse([
            'description' => $description, 'generation_mode' => 'strict_documents',
        ], [[
            'id' => 82, 'filename' => 'project.pdf', 'status' => 'ready', 'quality' => ['level' => 'good'],
            'extracted_text' => $context, 'document_understanding' => ['role_for_estimation' => 'quantity_source'],
            'quantity_takeoffs' => [[
                'scope_key' => 'specification_quantity', 'quantity' => 12, 'unit' => 'м2',
                'source_refs' => [['document_id' => 82]], 'normalized_payload' => ['quantity_key' => $quantityKey],
            ]],
        ]]);
        $planner = new PackagePlannerService;
        $profile = $planner->profileFromAnalysis($analysis);
        self::assertSame($expectedProfile, $profile->objectType);
        self::assertSame($expectedPackages, array_column($planner->plan($profile)->packages, 'key'));
        self::assertSame('document_limited', $planner->documentRequirements($profile)['coverage']);
        self::assertTrue($planner->documentRequirements($profile)['missing_for_full_estimate']);

        $analysis['evaluation_policy'] = ['mode' => 'universal', 'selected_sections' => [$selected]];
        $payload = $this->compiler()->compile($analysis, deferNormativePin: true);
        self::assertSame([], array_column($payload['package_plan']['packages'], 'key'));
        $items = [];
        foreach ($payload['local_estimates'] as $estimate) {
            foreach ($estimate['sections'] as $section) {
                array_push($items, ...$section['work_items']);
            }
        }
        $documentItems = array_values(array_filter($items, static fn (array $item): bool => ($item['metadata']['quantity_key'] ?? null) === $quantityKey));
        self::assertSame([], $documentItems);
        self::assertSame([], $items);
        self::assertNotContains($selected === 'roofing' ? 'roof' : 'heating', array_column($payload['local_estimates'], 'key'));
    }

    public static function explicitRequestEvidenceBranches(): iterable
    {
        yield 'document_evidence' => ['Устройство отопления', 'foundation.concrete', 'Ведомость объёмов', 'document_evidence', ['custom-foundation'], 'heating'];
        yield 'floor_plan_geometry' => ['Устройство кровли', 'rough.walls', 'Планировка помещений', 'floor_plan_geometry', ['rough_finishing', 'finish_finishing'], 'roofing'];
    }

    public function test_manual_scope_does_not_duplicate_document_backed_quantity_work(): void
    {
        $source = [['document_id' => 82, 'page_number' => 3]];
        $analysis = ['object' => ['building_type' => 'bridge', 'description' => 'Устройство фундамента'],
            'detected_structure' => ['explicit_work_scopes' => [['scope_type' => 'foundation', 'title' => 'Фундамент']]],
            'evaluation_policy' => ['mode' => 'universal', 'selected_sections' => ['structures']],
            'document_context' => ['scope_inferences' => [[
                'scope_type' => 'foundation', 'inference_type' => 'specification_takeoff',
                'title' => 'Бетонирование опор моста', 'source_refs' => $source, 'review_required' => false,
                'normalized_payload' => ['quantity_key' => 'foundation.concrete', 'quantity_value' => 12, 'unit' => 'м3'],
            ]], 'quantity_takeoffs' => [[
                'quantity_key' => 'foundation.concrete', 'quantity' => 12, 'unit' => 'м3', 'source_refs' => $source,
                'normalized_payload' => ['quantity_key' => 'foundation.concrete'],
            ]]]];
        $payload = $this->compiler()->compile($analysis, deferNormativePin: true);

        self::assertSame(['custom-foundation'], array_column($payload['package_plan']['packages'], 'key'));
        self::assertSame(['custom-foundation'], array_column($payload['local_estimates'], 'key'));
        $items = array_merge(...array_column($payload['local_estimates'][0]['sections'], 'work_items'));
        self::assertCount(1, $items);
        self::assertSame('12', $items[0]['quantity']);
        self::assertSame([['document_id' => 82, 'page_number' => 3]], $items[0]['source_refs']);
        self::assertSame('foundation.concrete', $items[0]['metadata']['quantity_key']);
        self::assertSame('document_limited', $payload['document_requirements']['coverage']);

        $analysis['evaluation_policy']['selected_sections'] = ['equipment'];
        $outside = $this->compiler()->compile($analysis, deferNormativePin: true);
        self::assertSame([], $outside['package_plan']['packages']);
        self::assertSame([], $outside['local_estimates']);
        self::assertTrue($outside['document_requirements']['missing_for_full_estimate']);
    }

    public function test_selected_scope_filters_both_declared_packages_and_compiled_positions(): void
    {
        $analysis = $this->analysis();
        $analysis['evaluation_policy'] = ['mode' => 'universal', 'price_policy' => 'catalog', 'selected_sections' => ['structures']];
        $payload = $this->compiler()->compile($analysis, deferNormativePin: true);
        $keys = array_column($payload['local_estimates'], 'key');

        self::assertNotEmpty($keys);
        self::assertSame($keys, array_column($payload['package_plan']['packages'], 'key'));
        self::assertNotContains('electrical', $keys);
        self::assertSame(array_sum(array_column($payload['package_plan']['packages'], 'target_items_min')), $payload['package_plan']['target_items_min_total']);
        self::assertSame($analysis['evaluation_policy'], $payload['evaluation_policy']);
    }

    public function test_universal_water_scopes_specialize_combined_profiles_without_including_the_other_system(): void
    {
        foreach (['warehouse', 'industrial', 'mixed_warehouse_office'] as $type) {
            foreach ([['sewerage'], ['water_supply'], ['water_supply', 'sewerage']] as $selected) {
                $analysis = ['object' => ['object_type' => $type], 'evaluation_policy' => ['mode' => 'universal', 'selected_sections' => $selected]];
                $payload = $this->compiler()->compile($analysis, deferNormativePin: true);
                $keys = array_column($payload['local_estimates'], 'key');
                $expected = $selected === ['sewerage'] ? ['sewerage'] : ($selected === ['water_supply'] ? ['plumbing'] : ['plumbing', 'sewerage']);
                self::assertSame($expected, $keys, $type.' '.implode(',', $selected));
                self::assertSame($keys, array_column($payload['package_plan']['packages'], 'key'));
                self::assertLessThanOrEqual(35, $payload['package_plan']['target_items_min_total']);
                self::assertLessThanOrEqual(80, $payload['package_plan']['target_items_max_total']);
                foreach ($payload['local_estimates'] as $estimate) {
                    foreach ($estimate['sections'] as $section) {
                        self::assertNotEmpty($section['work_items']);
                        foreach ($section['work_items'] as $item) {
                            self::assertSame($estimate['scope_type'], $item['work_category']);
                        }
                    }
                }
                $evaluation = (new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalEvaluationBuilder)->build(
                    $payload, new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot([], [], [], []));
                self::assertSame($selected, $evaluation['selected_scope']);
                self::assertFalse($evaluation['can_confirm_for_apply']);
                foreach ($evaluation['scenarios'][0]['positions'] as $position) {
                    self::assertContains($position['section'], $selected);
                    self::assertNull($position['quantity']);
                }
            }
            $legacy = $this->compiler()->compile(['object' => ['object_type' => $type]], deferNormativePin: true);
            self::assertContains('water_sewerage', array_column($legacy['package_plan']['packages'], 'key'));
        }
    }

    public function test_explicit_residential_equipment_is_planned_without_treating_total_count_as_a_specification(): void
    {
        foreach (['house', 'residential', 'коттедж'] as $type) {
            $payload = $this->compiler()->compile([
                'object' => ['object_type' => $type],
                'evaluation_policy' => ['mode' => 'universal', 'selected_sections' => ['equipment']],
                'document_context' => ['engineering_parameters' => ['equipment_count' => 6]],
            ], deferNormativePin: true);
            self::assertSame(['equipment'], array_column($payload['package_plan']['packages'], 'key'));
            self::assertSame(['equipment'], array_column($payload['local_estimates'], 'key'));
            $package = $payload['package_plan']['packages'][0];
            self::assertSame('Оборудование', $package['title']);
            self::assertSame('engineering', $package['scope_type']);
            self::assertSame('planned', $package['status']);
            self::assertSame(0, $package['actual_items_count']);
            self::assertSame(30, $package['target_items_min']);
            self::assertSame(80, $package['target_items_max']);
            self::assertArrayHasKey('totals', $package);
            self::assertArrayHasKey('quality_summary', $package);
            $items = array_merge(...array_column($payload['local_estimates'][0]['sections'], 'work_items'));
            self::assertSame(['equipment.purchase', 'equipment.delivery', 'equipment.install'], array_column(array_column($items, 'metadata'), 'composition_work_key'));
            self::assertSame(['equipment_purchase', 'delivery', 'installation'], array_column(array_column($items, 'metadata'), 'requirement_key'));
            self::assertSame(['equipment', 'other', 'work'], array_column(array_column($items, 'metadata'), 'ordinary_item_type'));
            foreach ($items as $item) {
                self::assertNull($item['quantity']);
                self::assertSame('count', $item['unit']);
                self::assertNull($item['total_cost']);
                self::assertNull($item['price_source']);
                self::assertSame('not_calculated', $item['pricing_status']);
                self::assertSame('quantity_unknown', $item['pricing_blocker']);
                self::assertTrue($item['skip_normative_matching']);
                self::assertTrue($item['metadata']['requires_specification']);
                foreach (['materials', 'labor', 'machinery', 'other_resources'] as $resource) {
                    self::assertSame([], $item[$resource]);
                }
            }
            $evaluation = (new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalEvaluationBuilder)->build(
                $payload, new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot([], [], [], []));
            self::assertSame(['equipment'], $evaluation['selected_scope']);
            self::assertFalse($evaluation['can_confirm_for_apply']);
        }
    }

    public function test_residential_equipment_requires_explicit_universal_selection_and_preserves_industrial_definitions(): void
    {
        foreach ([[], ['mode' => 'universal', 'selected_sections' => ['structures']], ['mode' => 'universal', 'selected_sections' => []]] as $policy) {
            $payload = $this->compiler()->compile(['object' => ['object_type' => 'house'], 'evaluation_policy' => $policy], deferNormativePin: true);
            self::assertNotContains('equipment', array_column($payload['package_plan']['packages'], 'key'));
            self::assertNotContains('equipment', array_column($payload['local_estimates'], 'key'));
        }
        foreach (['industrial', 'factory'] as $type) {
            $industrial = $this->compiler()->compile(['object' => ['object_type' => $type],
                'evaluation_policy' => ['mode' => 'universal', 'profile_id' => 'industrial', 'selected_sections' => ['equipment']]], deferNormativePin: true);
            self::assertSame(['equipment', 'commissioning'], array_column($industrial['package_plan']['packages'], 'key'));
            $items = array_merge(...array_column($industrial['local_estimates'][0]['sections'], 'work_items'));
            self::assertSame(['equipment.units_count', 'equipment.deliveries_count', 'equipment.units_count'], array_column($items, 'quantity_formula'));
            self::assertSame(['equipment_purchase', 'delivery', 'installation'], array_column(array_column($items, 'metadata'), 'requirement_key'));
        }
        $house = $this->compiler()->compile(['object' => ['object_type' => 'house'],
            'evaluation_policy' => ['mode' => 'universal', 'profile_id' => 'industrial', 'selected_sections' => ['equipment']]], deferNormativePin: true);
        self::assertSame(['equipment'], array_column($house['package_plan']['packages'], 'key'));
        $items = array_merge(...array_column($house['local_estimates'][0]['sections'], 'work_items'));
        self::assertSame(['equipment.purchase', 'equipment.delivery', 'equipment.install'], array_column(array_column($items, 'metadata'), 'composition_work_key'));
    }

    public function test_selected_equipment_candidates_obey_the_frozen_work_item_ceiling(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('estimate_work_items_exceed_approved_plan');
        $this->compiler()->compile(['object' => ['object_type' => 'house'],
            'evaluation_policy' => ['mode' => 'universal', 'selected_sections' => ['equipment']]], deferNormativePin: true, workItemLimit: 2);
    }

    #[DataProvider('residentialEquipmentSignals')]
    public function test_selected_equipment_does_not_expand_an_incidental_residential_element(array $signals): void
    {
        $analysis = array_replace_recursive(['object' => ['object_type' => 'custom'],
            'evaluation_policy' => ['mode' => 'universal', 'selected_sections' => ['equipment']],
            'object_profile' => ['object_type' => 'industrial']], $signals);
        $payload = $this->compiler()->compile($analysis, deferNormativePin: true);
        self::assertSame('custom', $payload['object_profile']['object_type']);
        self::assertSame([], $payload['local_estimates']);
        self::assertSame('document_limited', $payload['document_requirements']['coverage']);
    }

    public static function residentialEquipmentSignals(): array
    {
        return [[['document_context' => ['context_text' => 'Жилой дом.']]],
            [['object' => ['zones' => [['label' => 'Жилой дом']]]]],
            [['object' => ['zones' => [['scope_key' => 'house']]]]]];
    }

    public function test_primary_residential_description_still_enables_the_known_profile(): void
    {
        $payload = $this->compiler()->compile([
            'object' => ['object_type' => 'custom', 'description' => 'Индивидуальный жилой дом'],
            'evaluation_policy' => ['mode' => 'universal', 'selected_sections' => ['equipment']],
        ], deferNormativePin: true);

        self::assertSame('house', $payload['object_profile']['object_type']);
        self::assertSame(['equipment'], array_column($payload['local_estimates'], 'key'));
    }

    public function test_selected_residential_equipment_does_not_import_other_engineering_inferences(): void
    {
        $inferences = [];
        foreach (['plumbing.pipe' => 'plumbing', 'electrical.power_lines' => 'electrical', 'heating.pipe' => 'heating', 'ventilation.air_exchange' => 'ventilation'] as $key => $scope) {
            $inferences[] = ['scope_type' => $scope, 'inference_type' => 'specification_takeoff',
                'title' => $key, 'review_required' => false, 'source_refs' => [['document_id' => 82]],
                'normalized_payload' => ['quantity_key' => $key, 'quantity_value' => 7, 'unit' => 'м']];
        }
        $payload = $this->compiler()->compile(['object' => ['object_type' => 'house'],
            'evaluation_policy' => ['mode' => 'universal', 'selected_sections' => ['equipment']],
            'document_context' => ['scope_inferences' => $inferences]], deferNormativePin: true);
        self::assertSame(['equipment'], array_column($payload['local_estimates'], 'key'));
        $items = array_merge(...array_column($payload['local_estimates'][0]['sections'], 'work_items'));
        self::assertSame(['equipment.purchase', 'equipment.delivery', 'equipment.install'], array_column(array_column($items, 'metadata'), 'composition_work_key'));
        foreach ($items as $item) {
            self::assertNull($item['quantity']);
            self::assertSame([], $item['source_refs']);
        }
    }

    public function test_other_engineering_document_references_remain_review_notes_outside_selected_equipment(): void
    {
        $payload = $this->compiler()->compile(['object' => ['object_type' => 'house'],
            'evaluation_policy' => ['mode' => 'universal', 'selected_sections' => ['equipment']],
            'source_documents' => [['id' => 82, 'status' => 'ready', 'quality' => ['level' => 'good'],
                'document_understanding' => ['role_for_estimation' => 'quantity_source'],
                'text' => "ФЕР 08-02-001-01 Прокладка кабеля 10 м\nФЕР 16-01-001-01 Прокладка труб водоснабжения 10 м"]]], deferNormativePin: true);
        $estimates = array_column($payload['local_estimates'], null, 'key');
        $equipment = array_merge(...array_column($estimates['equipment']['sections'], 'work_items'));
        self::assertCount(3, $equipment);
        self::assertSame(['qualitative_work_catalog'], array_values(array_unique(array_column(array_column($equipment, 'metadata'), 'generation_source'))));
        $review = array_merge(...array_column($estimates['document_review']['sections'], 'work_items'));
        self::assertCount(2, $review);
        foreach ($review as $item) {
            self::assertSame('review_note', $item['item_type']);
            self::assertSame('not_applicable', $item['pricing_status']);
            self::assertSame('work_scope_review_required', $item['pricing_blocker']);
            self::assertSame(82, $item['source_refs'][0]['document_id']);
        }
    }

    public function test_structures_selection_keeps_real_facade_and_mixed_structure_packages(): void
    {
        foreach (['house' => ['facade'], 'mixed_warehouse_office' => ['envelope', 'office_partitions', 'entrance_group']] as $type => $expected) {
            $payload = $this->compiler()->compile(['object' => ['object_type' => $type],
                'evaluation_policy' => ['mode' => 'universal', 'selected_sections' => ['structures']]], deferNormativePin: true);
            foreach ($expected as $key) {
                self::assertContains($key, array_column($payload['local_estimates'], 'key'));
            }
        }
    }

    #[DataProvider('openingModes')]
    public function test_mixed_opening_packages_do_not_repeat_source_backed_windows_doors_gates_or_loading_nodes(string $mode): void
    {
        $quantities = ['openings.windows' => 10, 'openings.doors' => 20, 'warehouse.gates' => 3, 'warehouse.loading_nodes' => 4];
        $analysis = ['object' => ['object_type' => 'mixed_warehouse_office'],
            'planning_signals' => ['generation_mode' => $mode === 'universal' ? 'ai_assisted' : $mode],
            'evaluation_policy' => ['mode' => 'universal', 'selected_sections' => ['structures']],
            'document_context' => ['quantity_takeoffs' => array_map(static fn (string $key, int $value): array => [
                'quantity_key' => $key, 'quantity' => $value, 'unit' => 'шт', 'source_refs' => [['document_id' => 82]],
                'normalized_payload' => ['quantity_key' => $key],
            ], array_keys($quantities), array_values($quantities))]];
        if ($mode !== 'universal') {
            unset($analysis['evaluation_policy']);
        }
        $payload = $this->compiler()->compile($analysis, deferNormativePin: true);
        $matches = [];
        foreach ($payload['local_estimates'] as $estimate) {
            foreach ($estimate['sections'] as $section) {
                foreach ($section['work_items'] as $item) {
                    $key = $item['quantity_formula'] ?? null;
                    if (is_string($key) && array_key_exists($key, $quantities)) {
                        $matches[$key][] = ['package' => $estimate['key'], 'item' => $item];
                    }
                }
            }
        }
        foreach ($quantities as $key => $quantity) {
            foreach ($matches[$key] ?? [] as $match) {
                self::assertSame((string) $quantity, $match['item']['quantity']);
                self::assertSame([['document_id' => 82]], $match['item']['source_refs']);
            }
            self::assertCount(1, $matches[$key] ?? [], $key);
            self::assertSame(str_starts_with($key, 'openings.') ? 'entrance_group' : 'gates', $matches[$key][0]['package']);
            self::assertSame((string) $quantity, $matches[$key][0]['item']['quantity']);
            self::assertSame([['document_id' => 82]], $matches[$key][0]['item']['source_refs']);
        }
    }

    public static function openingModes(): array
    {
        return [['universal'], ['strict'], ['ai_assisted']];
    }

    public function test_coexisting_packages_do_not_reuse_the_same_lighting_network_or_sanitary_takeoff(): void
    {
        foreach (['house', 'warehouse', 'industrial', 'mixed_warehouse_office'] as $type) {
            foreach (['universal', 'strict'] as $mode) {
                $quantities = ['lighting.lines' => [40, 'lighting']];
                if ($type === 'mixed_warehouse_office') {
                    $quantities += ['warehouse.low_current' => [60, 'low_current'], 'office.network_points' => [6, 'low_current'], 'server.room' => [7, 'server_room']];
                    if ($mode === 'strict') {
                        $quantities += ['plumbing.pipe' => [64, 'water_sewerage'], 'sanitary.tile' => [10, 'sanitary_rooms'],
                            'sanitary.waterproofing' => [20, 'sanitary_rooms'], 'sanitary.floor_tile' => [30, 'sanitary_rooms']];
                    }
                }
                $analysis = ['object' => ['object_type' => $type],
                    'document_context' => ['quantity_takeoffs' => array_map(static fn (string $key, array $value): array => [
                        'quantity_key' => $key, 'quantity' => $value[0], 'unit' => 'м2', 'source_refs' => [['document_id' => 82]],
                        'normalized_payload' => ['quantity_key' => $key],
                    ], array_keys($quantities), array_values($quantities))]];
                if ($mode === 'universal') {
                    $analysis['evaluation_policy'] = ['mode' => 'universal', 'selected_sections' => ['electricity']];
                }
                $payload = $this->compiler()->compile($analysis, deferNormativePin: true);
                $matches = [];
                foreach ($payload['local_estimates'] as $estimate) {
                    foreach ($estimate['sections'] as $section) {
                        foreach ($section['work_items'] as $item) {
                            $key = $item['quantity_formula'] ?? null;
                            if (is_string($key) && array_key_exists($key, $quantities)) {
                                $matches[$key][] = ['package' => $estimate['key'], 'item' => $item];
                            }
                        }
                    }
                }
                foreach ($quantities as $key => [$quantity, $owner]) {
                    foreach ($matches[$key] ?? [] as $match) {
                        self::assertSame((string) $quantity, $match['item']['quantity']);
                    }
                    self::assertCount(1, $matches[$key] ?? [], $type.' '.$mode.' '.$key);
                    self::assertSame($owner, $matches[$key][0]['package']);
                }
            }
        }
    }

    public function test_mixed_opening_inferences_do_not_reenter_the_other_specialized_package(): void
    {
        foreach (['openings.windows' => 'entrance_group', 'openings.doors' => 'entrance_group', 'openings.gates' => 'gates'] as $key => $expected) {
            $payload = $this->compiler()->compile(['object' => ['object_type' => 'mixed_warehouse_office'],
                'evaluation_policy' => ['mode' => 'universal', 'selected_sections' => ['structures']],
                'document_context' => ['scope_inferences' => [['scope_type' => 'openings', 'title' => 'Позиция из ведомости',
                    'normalized_payload' => ['quantity_key' => $key, 'quantity_value' => 7, 'unit' => 'шт'],
                ]]]], deferNormativePin: true);
            $matches = [];
            foreach ($payload['local_estimates'] as $estimate) {
                foreach ($estimate['sections'] as $section) {
                    foreach ($section['work_items'] as $item) {
                        if (($item['metadata']['generation_source'] ?? null) === 'scope_inference'
                            && ($item['quantity_formula'] ?? null) === $key) {
                            $matches[] = ['package' => $estimate['key'], 'item' => $item];
                        }
                    }
                }
            }
            self::assertCount(1, $matches, $key);
            self::assertSame($expected, $matches[0]['package']);
            self::assertSame('7', $matches[0]['item']['quantity']);
        }
    }

    public function test_universal_water_with_an_explicit_sanitary_package_uses_each_takeoff_once(): void
    {
        foreach ([false, true] as $explicitSanitaryPackage) {
            $quantities = ['plumbing.pipe' => 64, 'sanitary.points' => 2, 'sanitary.showers' => 3, 'sanitary.toilets' => 4, 'sanitary.washbasins' => 5];
            $selected = $explicitSanitaryPackage ? ['water_supply', 'sanitary_rooms'] : ['water_supply'];
            $payload = $this->compiler()->compile(['object' => ['object_type' => 'mixed_warehouse_office'],
                'evaluation_policy' => ['mode' => 'universal', 'selected_sections' => $selected],
                'document_context' => ['quantity_takeoffs' => array_map(static fn (string $key, int $quantity): array => [
                    'quantity_key' => $key, 'quantity' => $quantity, 'unit' => $key === 'plumbing.pipe' ? 'м' : 'шт',
                    'source_refs' => [['document_id' => 82]],
                    'normalized_payload' => ['quantity_key' => $key, 'review_required' => false],
                ], array_keys($quantities), array_values($quantities))]], deferNormativePin: true);
            $matches = [];
            foreach ($payload['local_estimates'] as $estimate) {
                foreach ($estimate['sections'] as $section) {
                    foreach ($section['work_items'] as $item) {
                        $key = $item['quantity_formula'] ?? null;
                        if (is_string($key) && array_key_exists($key, $quantities)) {
                            $matches[$key][] = ['package' => $estimate['key'], 'quantity' => $item['quantity']];
                        }
                    }
                }
            }
            foreach ($quantities as $key => $quantity) {
                self::assertCount(1, $matches[$key] ?? [], $key.' '.implode(',', $selected));
                self::assertSame((string) $quantity, $matches[$key][0]['quantity']);
                self::assertSame($explicitSanitaryPackage && str_starts_with($key, 'sanitary.') ? 'sanitary_rooms' : 'plumbing', $matches[$key][0]['package']);
            }
        }
    }

    public function test_raw_manual_description_without_dimensions_waits_for_composer_semantics(): void
    {
        $analysis = $this->analysis();
        $analysis['object'] = ['description' => 'Устройство фундамента'];
        $analysis['document_context'] = [];
        $this->assertNoPreComposerWork($analysis);
    }

    private function analysis(): array
    {
        return [
            'object' => ['object_type' => 'house', 'description' => 'Монолитный фундамент', 'area' => 100],
            'detected_structure' => [
                'scopes' => [['scope_type' => 'foundation', 'title' => 'Фундамент', 'source_refs' => []]],
                'explicit_work_scopes' => [['scope_type' => 'foundation', 'title' => 'Фундамент', 'source_refs' => []]],
            ],
            'document_context' => ['quantity_model' => ['quantities' => ['concrete_volume' => ['value' => 12.5, 'unit' => 'm3', 'source' => 'document', 'source_refs' => []]]]],
            'regional_context' => [],
            'planning_signals' => ['generation_mode' => 'strict'],
        ];
    }
}
