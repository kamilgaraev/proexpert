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

    #[DataProvider('unknownSelectedSections')]
    public function test_unknown_selected_scope_does_not_create_house_work_or_claim_completeness(array $selected): void
    {
        $analysis = ['object' => ['building_type' => 'bridge', 'description' => 'Мост через реку'],
            'evaluation_policy' => ['mode' => 'universal', 'price_policy' => 'catalog', 'selected_sections' => $selected],
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
    }

    #[DataProvider('requestedUnknownScopes')]
    public function test_named_unknown_preserves_each_supported_explicit_work_scope(string $description, string $scope, string $package, string $selected): void
    {
        $analysis = (new ConstructionSemanticParser)->parse(['building_type' => 'bridge', 'description' => $description], []);
        $planner = new PackagePlannerService;
        $profile = $planner->profileFromAnalysis($analysis);
        self::assertSame([$scope], array_column($analysis['detected_structure']['explicit_work_scopes'], 'scope_type'));
        self::assertSame('bridge', $profile->objectType);
        self::assertSame([$package], array_column($planner->plan($profile)->packages, 'key'));

        $analysis['evaluation_policy'] = ['mode' => 'universal', 'selected_sections' => [$selected]];
        $payload = $this->compiler()->compile($analysis, deferNormativePin: true);
        self::assertSame([$package], array_column($payload['package_plan']['packages'], 'key'));
        self::assertSame([$package], array_column($payload['local_estimates'], 'key'));
        self::assertSame('document_limited', $payload['document_requirements']['coverage']);
        self::assertTrue($payload['document_requirements']['missing_for_full_estimate']);
        foreach ($payload['local_estimates'] as $estimate) {
            foreach ($estimate['sections'] as $section) {
                foreach ($section['work_items'] as $item) {
                    self::assertNull($item['quantity']);
                    self::assertNull($item['total_cost']);
                    self::assertNull($item['price_source']);
                }
            }
        }
        if ($scope === 'fire_safety') {
            self::assertSame([], $payload['local_estimates'][0]['sections'][0]['work_items']);
        }
        if ($scope === 'earthworks') {
            $items = $payload['local_estimates'][0]['sections'][0]['work_items'];
            self::assertNotEmpty($items);
            self::assertContains('earth.trench', array_column(array_column($items, 'metadata'), 'quantity_key'));
            self::assertNotContains('site.geodesy', array_column(array_column($items, 'metadata'), 'quantity_key'));
        }
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
        yield 'site' => ['Разгрузочная зона', 'site', 'site_preparation', 'earthworks'];
    }

    #[DataProvider('multipleExplicitScopes')]
    public function test_multiple_explicit_work_scopes_survive_universal_selection_without_nested_keyword_duplicates(string $description, array $expected, array $selected): void
    {
        $analysis = (new ConstructionSemanticParser)->parse(['building_type' => 'bridge', 'description' => $description], []);
        $analysis['evaluation_policy'] = ['mode' => 'universal', 'selected_sections' => $selected];
        $payload = $this->compiler()->compile($analysis, deferNormativePin: true);

        self::assertSame($expected, array_column($payload['package_plan']['packages'], 'key'));
        self::assertSame($expected, array_column($payload['local_estimates'], 'key'));
        self::assertSame('document_limited', $payload['document_requirements']['coverage']);
        foreach ($payload['local_estimates'] as $estimate) {
            foreach ($estimate['sections'] as $section) {
                foreach ($section['work_items'] as $item) {
                    self::assertNull($item['quantity']);
                    self::assertNull($item['total_cost']);
                }
            }
        }
    }

    public static function multipleExplicitScopes(): iterable
    {
        yield 'foundation_and_walls' => ['Устройство фундамента и стен', ['foundation', 'walls'], ['structures']];
        yield 'water_and_sewerage' => ['Водоснабжение и канализация', ['plumbing', 'sewerage'], ['water_supply', 'sewerage']];
        yield 'separate_finishing_phases' => ['Черновая отделка и чистовая отделка', ['rough_finishing', 'finish_finishing'], ['finishing']];
        yield 'specific_overlapping_phrase' => ['Штукатурка стен', ['rough_finishing'], ['finishing', 'structures']];
        yield 'specific_phrase_and_independent_wall' => ['Черновая отделка и стены', ['rough_finishing', 'walls'], ['finishing', 'structures']];
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

    public function test_known_profiles_preserve_their_explicit_fire_alarm_request_in_automation(): void
    {
        foreach (['warehouse', 'industrial', 'house'] as $type) {
            $analysis = (new ConstructionSemanticParser)->parse(['building_type' => $type, 'description' => 'Пожарная сигнализация'], []);
            $analysis['evaluation_policy'] = ['mode' => 'universal', 'selected_sections' => ['automation']];
            $payload = $this->compiler()->compile($analysis, deferNormativePin: true);
            $keys = array_column($payload['local_estimates'], 'key');
            self::assertContains('requested_fire_alarm', $keys, $type);
            self::assertNotContains('fire_safety', $keys, $type);
            $alarm = $payload['local_estimates'][array_search('requested_fire_alarm', $keys, true)];
            self::assertSame($type === 'warehouse' ? ['Пожарная сигнализация'] : [], array_column($alarm['sections'][0]['work_items'], 'name'), $type);
        }
    }

    #[DataProvider('explicitScopeExclusions')]
    public function test_explicitly_excluded_work_is_not_a_positive_requested_scope(string $description, array $expected): void
    {
        $analysis = (new ConstructionSemanticParser)->parse(['building_type' => 'bridge', 'description' => $description], []);
        $analysis['evaluation_policy'] = ['mode' => 'universal', 'selected_sections' => ['structures']];
        $payload = $this->compiler()->compile($analysis, deferNormativePin: true);
        self::assertSame($expected, array_column($payload['package_plan']['packages'], 'key'));
        self::assertSame($description, $analysis['object']['manual_description']);
        self::assertSame('document_limited', $payload['document_requirements']['coverage']);
    }

    public static function explicitScopeExclusions(): iterable
    {
        yield 'without_walls' => ['Устройство фундамента без стен', ['foundation']];
        yield 'walls_not_required' => ['Устройство фундамента, стены не требуются', ['foundation']];
        yield 'foundation_not_required' => ['Фундамент не требуется, устройство стен', ['walls']];
        yield 'ambiguous_postfix_list_not_required' => ['Устройство фундамента и стены не требуются', []];
        yield 'conjunct_foundation_not_required' => ['Фундамент не требуется и устройство стен', ['walls']];
        yield 'without_compound_scope' => ['Устройство фундамента без стен и перекрытий', ['foundation']];
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

    #[DataProvider('explicitObjectsWithoutIncidentalScopes')]
    public function test_weak_material_or_adjective_hints_do_not_authorize_another_explicit_work_scope(string $description, array $expected): void
    {
        $analysis = (new ConstructionSemanticParser)->parse(['building_type' => 'bridge', 'description' => $description], []);
        $analysis['evaluation_policy'] = ['mode' => 'universal', 'selected_sections' => [
            'structures', 'roofing', 'earthworks', 'finishing', 'ventilation', 'water_supply', 'electricity',
        ]];
        $payload = $this->compiler()->compile($analysis, deferNormativePin: true);
        self::assertSame($expected, array_column($payload['package_plan']['packages'], 'key'));
        self::assertSame('document_limited', $payload['document_requirements']['coverage']);
        self::assertSame($description, $analysis['object']['manual_description']);
        if ($expected === []) {
            $planner = new PackagePlannerService;
            self::assertSame([], $planner->plan($planner->profileFromAnalysis($analysis))->packages);
        }
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
        yield 'site_work' => ['Благоустройство территории', ['site_preparation']];
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
        yield 'explicit_access_operation' => ['Устройство подъезда', ['site_preparation']];
        yield 'explicit_driveway_operation' => ['Устройство проезда', ['site_preparation']];
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

    public function test_explicit_road_access_nouns_keep_the_existing_optional_road_signal(): void
    {
        foreach (['Дорога', 'Устройство подъезда', 'Устройство проезда'] as $description) {
            $analysis = (new ConstructionSemanticParser)->parse(['building_type' => 'bridge', 'description' => $description], []);
            $planner = new PackagePlannerService;
            $profile = $planner->profileFromAnalysis($analysis);
            self::assertTrue($profile->planningSignals['roads']);
            self::assertContains('roads', array_column($planner->plan($profile)->packages, 'key'));
        }
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
    public function test_document_finishing_phase_does_not_suppress_a_different_requested_phase(string $description, string $quantityKey, string $requestedPackage): void
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

        self::assertSame(['custom-finishing', $requestedPackage], array_column($payload['package_plan']['packages'], 'key'));
        self::assertSame(['custom-finishing', $requestedPackage], array_column($payload['local_estimates'], 'key'));
        $items = array_merge(...array_map(static fn (array $estimate): array => array_merge(...array_column($estimate['sections'], 'work_items')), $payload['local_estimates']));
        $documentItems = array_values(array_filter($items, static fn (array $item): bool => ($item['metadata']['quantity_key'] ?? null) === $quantityKey));
        self::assertSame(12, array_sum(array_column($documentItems, 'quantity')));
        self::assertCount(1, $documentItems);
        self::assertSame('12', $documentItems[0]['quantity']);
        self::assertSame(82, $documentItems[0]['source_refs'][0]['document_id']);
        self::assertSame('document_limited', $payload['document_requirements']['coverage']);
        foreach (array_merge(...array_column($payload['local_estimates'][1]['sections'], 'work_items')) as $item) {
            self::assertNull($item['quantity']);
            self::assertNull($item['total_cost']);
            self::assertSame([], $item['source_refs']);
        }
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

        self::assertSame(['custom-finishing', 'rough_finishing'], array_column($payload['local_estimates'], 'key'));
        $documentItems = array_merge(...array_column($payload['local_estimates'][0]['sections'], 'work_items'));
        self::assertCount(2, $documentItems);
        self::assertSame(['12', '12'], array_column($documentItems, 'quantity'));
        self::assertSame(['room-a', 'room-b'], array_map(static fn (array $item): string => $item['metadata']['scope_inference']['normalized_payload']['entity_id'], $documentItems));
        $outlineItems = array_merge(...array_column($payload['local_estimates'][1]['sections'], 'work_items'));
        self::assertNotEmpty($outlineItems);
        foreach ($outlineItems as $item) {
            self::assertNull($item['quantity']);
            self::assertNull($item['total_cost']);
            self::assertArrayNotHasKey('scope_inference', $item['metadata']);
        }
    }

    public function test_named_unknown_explicit_foundation_remains_a_limited_outline_in_selected_structures(): void
    {
        $analysis = (new ConstructionSemanticParser)->parse(['building_type' => 'bridge', 'description' => 'Устройство фундамента'], []);
        $analysis['evaluation_policy'] = ['mode' => 'universal', 'selected_sections' => ['structures']];
        $payload = $this->compiler()->compile($analysis, deferNormativePin: true);

        self::assertSame('bridge', $payload['object_profile']['object_type']);
        self::assertSame(['foundation'], array_column($payload['local_estimates'], 'key'));
        $items = array_merge(...array_column($payload['local_estimates'][0]['sections'], 'work_items'));
        self::assertNotEmpty($items);
        foreach ($items as $item) {
            self::assertNull($item['quantity']);
            self::assertNull($item['total_cost']);
            self::assertNull($item['price_source']);
        }
        self::assertTrue($payload['document_requirements']['missing_for_full_estimate']);
    }

    #[DataProvider('explicitRequestEvidenceBranches')]
    public function test_explicit_request_survives_document_and_geometry_only_branches(string $description, string $quantityKey, string $context, string $expectedProfile, array $expectedPackages, string $selected): void
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
        $requestedKey = $selected === 'roofing' ? 'roof' : 'heating';
        self::assertSame([$requestedKey], array_column($payload['local_estimates'], 'key'));
        $items = array_merge(...array_column($payload['local_estimates'][0]['sections'], 'work_items'));
        self::assertNotEmpty($items);
        foreach ($items as $item) {
            self::assertNull($item['quantity']);
            self::assertNull($item['total_cost']);
            self::assertNull($item['price_source']);
            self::assertSame([], $item['source_refs']);
        }
    }

    public static function explicitRequestEvidenceBranches(): iterable
    {
        yield 'document_evidence' => ['Устройство отопления', 'foundation.concrete', 'Ведомость объёмов', 'document_evidence', ['custom-foundation', 'heating'], 'heating'];
        yield 'floor_plan_geometry' => ['Устройство кровли', 'rough.walls', 'Планировка помещений', 'floor_plan_geometry', ['rough_finishing', 'finish_finishing', 'roof'], 'roofing'];
    }

    public function test_unknown_document_work_retains_source_and_selected_scope(): void
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
        self::assertNotEmpty($items);
        foreach ($items as $item) {
            self::assertNotEmpty($item['source_refs']);
            self::assertSame(82, $item['source_refs'][0]['document_id']);
            self::assertSame(0, $item['total_cost']);
            self::assertNull($item['price_source']);
        }
        self::assertSame(['12'], array_values(array_unique(array_column($items, 'quantity'))));
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
        foreach (['industrial', 'house'] as $type) {
            $industrial = $this->compiler()->compile(['object' => ['object_type' => $type],
                'evaluation_policy' => ['mode' => 'universal', 'profile_id' => 'industrial', 'selected_sections' => ['equipment']]], deferNormativePin: true);
            self::assertSame(['equipment', 'commissioning'], array_column($industrial['package_plan']['packages'], 'key'));
            $items = array_merge(...array_column($industrial['local_estimates'][0]['sections'], 'work_items'));
            self::assertSame(['equipment.units_count', 'equipment.deliveries_count', 'equipment.units_count'], array_column($items, 'quantity_formula'));
            self::assertSame(['equipment_purchase', 'delivery', 'installation'], array_column(array_column($items, 'metadata'), 'requirement_key'));
        }
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

    public function test_universal_description_without_dimensions_keeps_a_work_list_with_unknown_volumes(): void
    {
        $analysis = $this->analysis();
        $analysis['object'] = ['description' => 'Устройство фундамента'];
        $analysis['document_context'] = [];
        $analysis['evaluation_policy'] = ['mode' => 'universal', 'price_policy' => 'catalog', 'profile_id' => 'construction', 'selected_sections' => ['structures']];
        $payload = $this->compiler()->compile($analysis, deferNormativePin: true);
        self::assertSame(['foundation'], array_column($payload['package_plan']['packages'], 'key'));
        self::assertSame('document_limited', $payload['document_requirements']['coverage']);
        $items = [];
        foreach ($payload['local_estimates'] as $estimate) {
            foreach ($estimate['sections'] as $section) {
                array_push($items, ...$section['work_items']);
            }
        }
        self::assertNotEmpty($items);
        foreach ($items as $item) {
            self::assertNull($item['quantity']);
            self::assertNull($item['total_cost']);
            self::assertNotSame('компл', $item['unit']);
        }
        self::assertNotEmpty((new \App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateCompositionProjector)->candidates($payload['local_estimates']));
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
