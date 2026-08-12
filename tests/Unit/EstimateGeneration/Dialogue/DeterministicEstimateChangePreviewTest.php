<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Dialogue;

use App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\DeterministicEstimateChangePreview;
use App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\EstimateCommandInterpretation;
use App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\EstimateDialogueContextSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\EstimateDialogueContextSnapshotRepository;
use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\AssembleMatchedResources;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\EstimateNormativeMatcher;
use App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologyRecommendation;
use App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologySystem;
use App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologySystemOption;
use App\BusinessModules\Addons\EstimateGeneration\Pricing\ResolveRegionalPrice;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\CurrentProjectDerivedQuantityService;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\DerivedQuantityFactory;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimatePricingService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeCandidatePresenter;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeMatchDecisionService;
use App\BusinessModules\Addons\EstimateGeneration\Services\ResourceAssemblyService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\EstimateGeneration\InMemoryProjectModelRepository;

final class DeterministicEstimateChangePreviewTest extends TestCase
{
    private const SOURCE = 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    #[Test]
    public function canonical_area_change_reprices_rows_and_ignores_cached_or_provider_totals(): void
    {
        $models = new InMemoryProjectModelRepository;
        $room = new Entity('room:1', 10, 20, 30, self::SOURCE, 'room', 'room:1');
        $lengthEvidence = new Evidence('evidence:length', 10, 20, 30, self::SOURCE, 'artifact:plan', 'cad', 1, null, 'length');
        $widthEvidence = new Evidence('evidence:width', 10, 20, 30, self::SOURCE, 'artifact:plan', 'cad', 1, null, 'width');
        $wasteEvidence = new Evidence('evidence:waste', 10, 20, 30, self::SOURCE, 'artifact:plan', 'cad', 1, null, 'waste');
        $models->saveSourceModel([$room], [
            new Fact('fact:length', 10, 20, 30, self::SOURCE, $room->id, 'length', '5', 'm', 1.0, 'document', 'confirmed', [$lengthEvidence->id]),
            new Fact('fact:width', 10, 20, 30, self::SOURCE, $room->id, 'width', '10', 'm', 1.0, 'document', 'confirmed', [$widthEvidence->id]),
            new Fact('fact:waste', 10, 20, 30, self::SOURCE, $room->id, 'waste_factor', '1.1', 'count', 1.0, 'document', 'confirmed', [$wasteEvidence->id]),
            new Fact('fact:roof-system', 10, 20, 30, self::SOURCE, $room->id, 'roof_covering_system', null, null, 1.0, 'unresolved', 'unresolved', []),
        ], [$lengthEvidence, $widthEvidence, $wasteEvidence]);
        $token = $models->snapshotForPlanning(10, 20, 30, 100)['token'];
        $catalogHash = str_repeat('c', 64);
        $technology = new TechnologyRecommendation(
            decisionKey: 'roof_covering_system.room',
            targetFactId: 'fact:roof-system',
            organizationId: 10,
            projectId: 20,
            sessionId: 30,
            sourceVersion: self::SOURCE,
            catalogVersion: 'technology:v1',
            catalogHash: $catalogHash,
            options: [
                $this->technologyOption('roof.metal', 'Монтаж металлической кровли', true),
                $this->technologyOption('roof.flexible', 'Монтаж гибкой кровли', false),
                $this->technologyOption('roof.unsupported', 'Неподдерживаемая кровля', false, 'floor_area + waste_factor'),
                $this->technologyOption('roof.conditional', 'Условная кровля', false, 'floor_area', 'conditional'),
            ],
            responseOptions: [],
            question: 'Выберите кровельную систему',
            conditional: false,
            missingFacts: [],
        );
        self::assertTrue($models->replaceTechnologyRecommendations(
            10, 20, 30, self::SOURCE, $token, 'technology:v1', $catalogHash, [$technology], [],
        ));
        self::assertTrue($models->replaceCompleteness(
            10, 20, 30, self::SOURCE, $token, 'technology:v1', $catalogHash,
            'rules:v1', str_repeat('d', 64), [], [],
        ));
        $capture = $models->snapshotForPlanning(10, 20, 30, 100);
        $draft = [
            'preview_simulations' => ['fact:length' => ['after_total' => '1250.5000']],
            'provider_cost' => '999999.0000',
            'regional_context' => [
                'region_id' => 16, 'price_zone_id' => 3, 'period_id' => 8,
                'estimate_regional_price_version_id' => 11,
            ],
            'local_estimates' => [[
                'sections' => [[
                    'work_items' => [[
                        'key' => 'floor.work', 'name' => 'Устройство пола', 'item_type' => 'priced_work',
                        'unit' => 'm2', 'quantity' => '50', 'pricing_status' => 'calculated', 'total_cost' => '500.00',
                        'metadata' => ['quantity_key' => 'floor_area', 'dependency_keys' => ['fact:length']],
                    ], [
                        'key' => 'stage5:current-roof', 'name' => 'Монтаж металлической кровли',
                        'item_type' => 'priced_work', 'unit' => 'm2', 'quantity' => '50',
                        'pricing_status' => 'calculated', 'total_cost' => '400.00',
                        'metadata' => [
                            'quantity_key' => 'floor_area',
                            'technology_decision_key' => 'roof_covering_system.room',
                            'technology_system_id' => 'roof.metal',
                            'dependency_keys' => ['roof_covering_system.room', 'floor_area'],
                        ],
                    ], [
                        'key' => 'unrelated.work', 'name' => 'Независимая работа', 'item_type' => 'priced_work',
                        'unit' => 'item', 'quantity' => '1', 'pricing_status' => 'calculated', 'total_cost' => '30.00',
                        'metadata' => ['quantity_key' => 'unrelated', 'dependency_keys' => ['fact:other']],
                    ]],
                ]],
            ]],
        ];
        $exact = new EstimateDialogueContextSnapshot(
            10, 20, 30, 1, [], [], $draft, $capture['snapshot'], $capture['token'], [],
            $models->currentTechnologyRecommendations(10, 20, 30),
            $models->currentCompleteness(10, 20, 30), [], [],
        );
        $snapshots = new class($exact) implements EstimateDialogueContextSnapshotRepository
        {
            public function __construct(private readonly EstimateDialogueContextSnapshot $snapshot) {}

            public function capture(int $organizationId, int $projectId, int $sessionId): EstimateDialogueContextSnapshot
            {
                return $this->snapshot;
            }
        };
        $match = [
            'version' => ['source_type' => 'fsnb', 'version_key' => 'fsnb:v1'],
            'price_version' => ['source_type' => 'regional_catalog', 'version_key' => 'prices:v1'],
            'selected' => [
                'key' => 'norm:floor', 'norm_id' => 100, 'code' => '11-01-001-01', 'name' => 'Устройство пола',
                'unit' => 'm2', 'collection' => ['code' => 'gesn', 'name' => 'ГЭСН', 'norm_type' => 'gesn'],
                'section' => ['code' => '', 'name' => 'Работы'], 'score' => 100, 'confidence' => 1.0,
                'match_reasons' => ['unit'], 'warnings' => [], 'work_composition' => ['Устройство пола'],
                'resources' => ['materials' => [[
                    'code' => '01.1.01.01-0001', 'name' => 'Материал', 'resource_type' => 'material',
                    'unit' => 'm2', 'quantity' => '1', 'unit_price' => '10', 'total_price' => '10',
                    'price_source' => 'regional_catalog', 'price_id' => 9001, 'linked_resource_id' => null,
                ]], 'labor' => [], 'machinery' => [], 'other' => []],
            ],
        ];
        $matcher = new class($match) extends EstimateNormativeMatcher
        {
            public function __construct(private readonly array $match) {}

            public function matchWorkItem(array $workItem, array $context = [], int $limit = 5): ?array
            {
                $selected = [
                    ...$this->match['selected'],
                    'name' => (string) ($workItem['name'] ?? ''),
                    'work_composition' => [(string) ($workItem['name'] ?? '')],
                    'code' => str_contains((string) ($workItem['name'] ?? ''), 'кровли')
                        ? '12-01-001-01'
                        : '11-01-001-01',
                ];

                return [...$this->match, 'selected' => $selected, 'candidates' => [$selected]];
            }
        };
        $decision = (new NormativeMatchDecisionService)->decide($match['selected'], [
            'key' => 'floor.work', 'name' => 'Устройство пола', 'item_type' => 'priced_work',
            'unit' => 'm2', 'quantity' => '60', 'validation_flags' => [],
        ]);
        self::assertTrue($decision->canUseForPricing, json_encode($decision->toArray(), JSON_THROW_ON_ERROR));
        $technologyDecision = (new NormativeMatchDecisionService)->decide([
            ...$match['selected'],
            'name' => 'Монтаж гибкой кровли',
            'work_composition' => ['Монтаж гибкой кровли'],
            'code' => '12-01-001-01',
        ], [
            'key' => 'roof.flexible', 'name' => 'Монтаж гибкой кровли', 'item_type' => 'priced_work',
            'unit' => 'm2', 'quantity' => '50', 'validation_flags' => [],
        ]);
        self::assertTrue(
            $technologyDecision->canUseForPricing,
            json_encode($technologyDecision->toArray(), JSON_THROW_ON_ERROR),
        );
        $service = new DeterministicEstimateChangePreview(
            $snapshots,
            new CurrentProjectDerivedQuantityService($models, new DerivedQuantityFactory),
            new ResourceAssemblyService($matcher, new NormativeMatchDecisionService, new NormativeCandidatePresenter),
            new AssembleMatchedResources,
            new EstimatePricingService(new ResolveRegionalPrice(static fn (int $priceId): array => [
                'id' => $priceId, 'region_id' => 16, 'price_zone_id' => 3, 'period_id' => 8,
                'regional_price_version_id' => 11, 'base_price' => '10.0000',
                'source_type' => 'regional_catalog', 'currency' => 'RUB',
            ])),
        );
        $session = new EstimateGenerationSession(['organization_id' => 10, 'project_id' => 20]);
        $session->id = 30;
        $session->exists = true;

        $deltas = [];
        foreach (['6', '7'] as $value) {
            $result = $service->calculate($session, new EstimateCommandInterpretation([
                'kind' => 'correct_fact', 'version' => 'v1', 'target_key' => 'fact:length',
                'value' => $value, 'dependency_keys' => ['fact:length'], 'provider_cost' => '999999.0000',
            ]));
            self::assertSame('known', $result['state'], json_encode($result, JSON_THROW_ON_ERROR));
            self::assertSame('500.00', $result['affected'][0]['before']['total_cost']);
            self::assertSame(((int) $value - 5).'00.0000', $result['affected'][0]['delta']);
            $deltas[] = $result['delta'];
        }

        self::assertSame(['100.0000', '200.0000'], $deltas);

        $technologyResult = $service->calculate($session, new EstimateCommandInterpretation([
            'kind' => 'select_technology', 'version' => 'v1',
            'decision_key' => 'roof_covering_system.room', 'option_id' => 'roof.flexible',
            'dependency_keys' => ['roof_covering_system.room'],
        ]));
        self::assertSame('known', $technologyResult['state'], json_encode($technologyResult, JSON_THROW_ON_ERROR));
        self::assertSame('650.0000', $technologyResult['delta']);
        $technologyKeys = [
            'stage5:current-roof',
            'stage5:'.hash('sha256', "roof.flexible\0work:roof.flexible"),
            'stage5:'.hash('sha256', "roof.flexible\0work:roof.flexible.deck"),
        ];
        sort($technologyKeys, SORT_STRING);
        self::assertSame($technologyKeys, array_column($technologyResult['affected'], 'stable_key'));
        self::assertSame(['roof_ready'], $technologyResult['assumptions']);
        self::assertSame(['weather_risk'], $technologyResult['risks']);

        $unsupportedResult = $service->calculate($session, new EstimateCommandInterpretation([
            'kind' => 'select_technology', 'version' => 'v1',
            'decision_key' => 'roof_covering_system.room', 'option_id' => 'roof.unsupported',
            'dependency_keys' => ['roof_covering_system.room'],
        ]));
        self::assertSame('unknown', $unsupportedResult['state']);
        self::assertSame(['canonical_formula_unsupported'], $unsupportedResult['blockers']);

        $conditionalResult = $service->calculate($session, new EstimateCommandInterpretation([
            'kind' => 'select_technology', 'version' => 'v1',
            'decision_key' => 'roof_covering_system.room', 'option_id' => 'roof.conditional',
            'dependency_keys' => ['roof_covering_system.room'],
        ]));
        self::assertSame('unknown', $conditionalResult['state']);
        self::assertSame([], $models->quantities);
        self::assertSame([], $models->currentQuantities);
    }

    private function technologyOption(
        string $id,
        string $work,
        bool $recommended,
        string $firstExpression = 'floor_area * waste_factor',
        string $applicability = 'applicable',
    ): TechnologySystemOption {
        $availability = ['available' => true, 'region' => '16', 'source' => 'catalog', 'version' => 'v1', 'reason' => 'available'];
        $cost = [...$availability, 'currency' => 'RUB', 'amount_minor' => null];

        return new TechnologySystemOption(
            new TechnologySystem(
                $id,
                'estimate_generation.planning.technology.system.test',
                [['roof_type' => 'pitched']],
                ['floor_area'],
                [['id' => 'material:'.$id, 'intent' => 'roof_material']],
                [
                    ['id' => 'work:'.$id, 'intent' => $work, 'quantity_formula_id' => 'area:'.$id, 'norm_intent_id' => 'norm:'.$id],
                    ['id' => 'work:'.$id.'.deck', 'intent' => $work.' — основание', 'quantity_formula_id' => 'deck:'.$id, 'norm_intent_id' => 'norm:'.$id.'.deck'],
                ],
                [['id' => 'machinery:'.$id, 'intent' => 'lifting_equipment']],
                [
                    ['id' => 'norm:'.$id, 'stable_intent' => 'fsnb.roof.installation', 'max_candidates' => 5],
                    ['id' => 'norm:'.$id.'.deck', 'stable_intent' => 'fsnb.roof.deck', 'max_candidates' => 5],
                ],
                [
                    [
                        'id' => 'area:'.$id,
                        'expression' => $firstExpression,
                        'result_unit' => 'm2',
                        'operands' => [
                            ['name' => 'floor_area', 'type' => 'fact', 'unit' => 'm2'],
                            ['name' => 'waste_factor', 'type' => 'parameter', 'unit' => 'count'],
                        ],
                    ],
                    [
                        'id' => 'deck:'.$id,
                        'expression' => 'floor_area',
                        'result_unit' => 'm2',
                        'operands' => [['name' => 'floor_area', 'type' => 'fact', 'unit' => 'm2']],
                    ],
                ],
                $availability,
                $cost,
                ['weather_risk'],
                ['roof_ready'],
                [['fact_type' => 'roof_type', 'values' => ['pitched'], 'score' => 1, 'reason' => 'compatible']],
                [['source' => 'technology_catalog']],
            ),
            100,
            [],
            $recommended,
            $work,
            $work,
            $applicability,
        );
    }
}
