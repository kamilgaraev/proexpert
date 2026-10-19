<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Planning;

use App\BusinessModules\Addons\EstimateGeneration\Enums\EstimateGenerationMode;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeContextPinResolver;
use App\BusinessModules\Addons\EstimateGeneration\Planning\WorkPlanCompiler;
use App\BusinessModules\Addons\EstimateGeneration\Planning\WorkPlannerResponseData;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateDecompositionService;
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
            'object' => ['description' => 'Монолитный фундамент', 'area' => 100],
            'detected_structure' => ['scopes' => [['scope_type' => 'foundation', 'title' => 'Фундамент', 'source_refs' => []]]],
            'document_context' => ['quantity_model' => ['quantities' => ['concrete_volume' => ['value' => 12.5, 'unit' => 'm3', 'source' => 'document', 'source_refs' => []]]]],
            'regional_context' => [],
            'planning_signals' => ['generation_mode' => 'strict'],
        ];
    }
}
