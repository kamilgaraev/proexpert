<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\EstimatePricingService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationNoAirWorkItemPolicy;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\EstimateNormativeMatcher;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeCandidatePresenter;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeMatchDecisionService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeScopeRuleCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\WorkIntentClassifier;
use App\BusinessModules\Addons\EstimateGeneration\Services\ResourceAssemblyService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\TestCase;

final class ResourceAssemblySafetyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container();
        $loader = new FileLoader(new Filesystem(), dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'lang');
        $translator = new Translator($loader, 'ru');
        $config = new Repository(['app' => ['fallback_locale' => 'ru']]);

        $container->instance('app', new class {
            public function getLocale(): string
            {
                return 'ru';
            }
        });
        $container->instance('config', $config);
        $container->instance(\Illuminate\Contracts\Config\Repository::class, $config);
        $container->instance('translator', $translator);
        $container->instance(\Illuminate\Contracts\Translation\Translator::class, $translator);
        $container->instance('log', new class {
            public function warning(string $message, array $context = []): void {}
        });

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

    public function test_selected_norm_with_incompatible_unit_stays_unpriced_candidate(): void
    {
        $workItem = [
            'key' => 'roof-insulation-1',
            'name' => 'Утепление кровли 200 мм',
            'unit' => 'м2',
            'quantity' => 194.25,
            'confidence' => 0.7,
            'validation_flags' => [],
            'materials' => [],
            'labor' => [],
            'machinery' => [],
            'work_intent' => [
                'scope' => 'roof',
                'action' => 'insulation',
                'preferred_section_prefixes' => ['12', '26'],
                'forbidden_section_prefixes' => ['01', '16'],
            ],
        ];
        $match = [
            'version' => ['source_type' => 'fsnb_2022', 'version_key' => '2026-05-07'],
            'price_version' => ['source_type' => 'fsbc', 'version_key' => '2026-05-07'],
            'selected' => $this->unsafeCandidate(),
            'candidates' => [$this->unsafeCandidate()],
        ];

        $service = new ResourceAssemblyService(
            new class ($match) extends EstimateNormativeMatcher {
                /**
                 * @param array<string, mixed> $match
                 */
                public function __construct(private readonly array $match) {}

                public function matchWorkItem(array $workItem, array $context = [], int $limit = 5): ?array
                {
                    return $this->match;
                }
            },
            new NormativeMatchDecisionService(),
            new NormativeCandidatePresenter(),
            new WorkIntentClassifier(new NormativeScopeRuleCatalog()),
        );

        $item = $service->enrich([$workItem], ['scope_type' => 'roof'])[0];
        $item = (new EstimatePricingService())->price([$item])[0];

        $this->assertSame('candidate', $item['normative_match']['status']);
        $this->assertSame([], $item['materials']);
        $this->assertSame([], $item['labor']);
        $this->assertSame([], $item['machinery']);
        $this->assertEquals(0.0, $item['total_cost']);
        $this->assertNull($item['price_source']);
        $this->assertContains('unit_mismatch', $item['normative_match']['warnings']);
        $this->assertContains('requires_normative_review', $item['validation_flags']);
    }

    public function test_safe_review_priced_candidate_keeps_normative_resources_and_price(): void
    {
        $workItem = [
            'key' => 'roof-insulation-1',
            'name' => 'Утепление кровли 200 мм',
            'unit' => 'м2',
            'quantity' => 100,
            'confidence' => 0.7,
            'validation_flags' => ['normative_required'],
            'materials' => [],
            'labor' => [],
            'machinery' => [],
            'work_intent' => [
                'scope' => 'roof',
                'action' => 'insulation',
                'preferred_section_prefixes' => ['12', '26'],
                'forbidden_section_prefixes' => ['01', '16'],
            ],
        ];
        $match = [
            'version' => ['source_type' => 'fsnb_2022', 'version_key' => '2026-05-31'],
            'price_version' => ['source_type' => 'fsbc', 'version_key' => '2026-05-31'],
            'selected' => $this->safeReviewCandidate(),
            'candidates' => [$this->safeReviewCandidate()],
        ];

        $service = new ResourceAssemblyService(
            new class ($match) extends EstimateNormativeMatcher {
                /**
                 * @param array<string, mixed> $match
                 */
                public function __construct(private readonly array $match) {}

                public function matchWorkItem(array $workItem, array $context = [], int $limit = 5): ?array
                {
                    return $this->match;
                }
            },
            new NormativeMatchDecisionService(),
            new NormativeCandidatePresenter(),
            new WorkIntentClassifier(new NormativeScopeRuleCatalog()),
        );

        $item = $service->enrich([$workItem], ['scope_type' => 'roof'])[0];
        $item = (new EstimatePricingService())->price([$item])[0];

        $this->assertSame('matched', $item['normative_match']['status']);
        $this->assertSame('review_priced', $item['normative_match']['decision']['status']);
        $this->assertSame('calculated_review_required', $item['pricing_status']);
        $this->assertGreaterThan(0, $item['total_cost']);
        $this->assertNotContains('safe_norm_required', $item['validation_flags']);
        $this->assertNotContains('pricing_not_calculated', $item['validation_flags']);
        $this->assertContains('safe_normative_analog', $item['validation_flags']);
    }

    public function test_quantity_review_item_is_not_matched_or_priced_before_quantity_confirmation(): void
    {
        $workItem = [
            'key' => 'finish-floor-review',
            'name' => 'Finish floor area',
            'item_type' => 'quantity_review',
            'unit' => 'm2',
            'quantity' => 120,
            'confidence' => 0.72,
            'validation_flags' => [],
            'materials' => [[
                'name' => 'stale material',
                'total_price' => 5000,
            ]],
            'labor' => [[
                'name' => 'stale labor',
                'total_price' => 2500,
            ]],
            'machinery' => [],
            'other_resources' => [],
            'work_cost' => 1000,
            'materials_cost' => 5000,
            'machinery_cost' => 0,
            'labor_cost' => 2500,
            'total_cost' => 8500,
            'price_source' => 'stale',
        ];

        $service = new ResourceAssemblyService(
            new class extends EstimateNormativeMatcher {
                public function __construct() {}

                public function matchWorkItem(array $workItem, array $context = [], int $limit = 5): ?array
                {
                    throw new \RuntimeException('Quantity review item must not be matched before confirmation.');
                }
            },
            new NormativeMatchDecisionService(),
            new NormativeCandidatePresenter(),
            new WorkIntentClassifier(new NormativeScopeRuleCatalog()),
        );

        $item = $service->enrich([$workItem], ['scope_type' => 'finishing'])[0];
        $item = (new EstimatePricingService())->price([$item])[0];

        $this->assertSame('quantity_review', $item['item_type']);
        $this->assertNull($item['normative_rate_code']);
        $this->assertNull($item['normative_match']);
        $this->assertSame([], $item['materials']);
        $this->assertSame([], $item['labor']);
        $this->assertSame([], $item['machinery']);
        $this->assertSame([], $item['other_resources']);
        $this->assertEquals(0.0, $item['total_cost']);
        $this->assertNull($item['price_source']);
        $this->assertSame('not_calculated', $item['pricing_status']);
        $this->assertSame('quantity_review_required', $item['pricing_blocker']);
        $this->assertContains('quantity_review_required', $item['validation_flags']);
        $this->assertContains('pricing_not_calculated', $item['validation_flags']);
    }

    public function test_priced_item_with_quantity_review_flag_is_converted_to_review_item_before_matching(): void
    {
        $workItem = [
            'key' => 'dirty-quantity-review',
            'name' => 'Floor area from drawing',
            'item_type' => 'priced_work',
            'unit' => 'm2',
            'quantity' => 120,
            'confidence' => 0.72,
            'validation_flags' => ['quantity_review_required'],
            'materials' => [[
                'name' => 'stale material',
                'total_price' => 5000,
            ]],
            'labor' => [],
            'machinery' => [],
            'other_resources' => [],
            'work_cost' => 1000,
            'materials_cost' => 5000,
            'machinery_cost' => 0,
            'labor_cost' => 0,
            'total_cost' => 6000,
            'price_source' => 'stale',
        ];

        $service = new ResourceAssemblyService(
            new class extends EstimateNormativeMatcher {
                public function __construct() {}

                public function matchWorkItem(array $workItem, array $context = [], int $limit = 5): ?array
                {
                    throw new \RuntimeException('Quantity review flagged item must not be matched before confirmation.');
                }
            },
            new NormativeMatchDecisionService(),
            new NormativeCandidatePresenter(),
            new WorkIntentClassifier(new NormativeScopeRuleCatalog()),
        );

        $item = $service->enrich([$workItem], ['scope_type' => 'finishing'])[0];
        $item = (new EstimatePricingService())->price([$item])[0];

        $this->assertSame('quantity_review', $item['item_type']);
        $this->assertNull($item['normative_rate_code']);
        $this->assertNull($item['normative_match']);
        $this->assertSame([], $item['materials']);
        $this->assertEquals(0.0, $item['total_cost']);
        $this->assertNull($item['price_source']);
        $this->assertSame('not_calculated', $item['pricing_status']);
        $this->assertSame('quantity_review_required', $item['pricing_blocker']);
        $this->assertContains('quantity_review_required', $item['validation_flags']);
        $this->assertContains('pricing_not_calculated', $item['validation_flags']);
    }

    public function test_generic_priced_item_is_not_matched_or_priced(): void
    {
        $workItem = [
            'key' => 'generic-complex-work',
            'name' => 'Комплекс строительных работ',
            'normative_search_text' => 'Комплекс строительных работ',
            'item_type' => 'priced_work',
            'unit' => 'компл',
            'quantity' => 1,
            'confidence' => 0.9,
            'validation_flags' => [],
            'materials' => [[
                'name' => 'stale material',
                'total_price' => 100000,
            ]],
            'labor' => [],
            'machinery' => [],
            'other_resources' => [],
            'work_cost' => 18000,
            'materials_cost' => 100000,
            'machinery_cost' => 0,
            'labor_cost' => 0,
            'total_cost' => 118000,
            'price_source' => 'stale',
        ];

        $service = new ResourceAssemblyService(
            new class extends EstimateNormativeMatcher {
                public function __construct() {}

                public function matchWorkItem(array $workItem, array $context = [], int $limit = 5): ?array
                {
                    throw new \RuntimeException('Generic priced item must not be matched.');
                }
            },
            new NormativeMatchDecisionService(),
            new NormativeCandidatePresenter(),
            new WorkIntentClassifier(new NormativeScopeRuleCatalog()),
        );

        $item = $service->enrich([$workItem], ['scope_type' => 'site'])[0];
        $item = (new EstimatePricingService())->price([$item])[0];

        $this->assertSame('priced_work', $item['item_type']);
        $this->assertSame([], $item['materials']);
        $this->assertSame([], $item['labor']);
        $this->assertSame([], $item['machinery']);
        $this->assertEquals(0.0, $item['total_cost']);
        $this->assertNull($item['price_source']);
        $this->assertSame('not_calculated', $item['pricing_status']);
        $this->assertSame(EstimateGenerationNoAirWorkItemPolicy::BLOCKER, $item['pricing_blocker']);
        $this->assertContains(EstimateGenerationNoAirWorkItemPolicy::FLAG, $item['validation_flags']);
        $this->assertContains(EstimateGenerationNoAirWorkItemPolicy::NO_AIR_FLAG, $item['validation_flags']);
    }

    public function test_manually_selected_scope_mismatch_norm_stays_unpriced_candidate(): void
    {
        $workItem = [
            'key' => 'finish-paint-1',
            'name' => 'Окраска стен',
            'description' => 'Окраска стен водно-дисперсионной краской',
            'unit' => 'м2',
            'quantity' => 80,
            'confidence' => 0.72,
            'validation_flags' => ['normative_required'],
            'materials' => [],
            'labor' => [],
            'machinery' => [],
            'work_intent' => [
                'scope' => 'finishing',
                'action' => 'painting',
                'preferred_section_prefixes' => ['15'],
                'forbidden_section_prefixes' => ['01'],
            ],
        ];
        $match = [
            'version' => ['source_type' => 'fsnb_2022', 'version_key' => '2026-05-31'],
            'price_version' => ['source_type' => 'fsbc', 'version_key' => '2026-05-31'],
            'selected' => $this->earthSquareCandidate(),
            'candidates' => [$this->earthSquareCandidate()],
        ];

        $item = $this->manualSelectionService()->applySelectedNormativeMatch($workItem, $match, ['scope_type' => 'finishing']);
        $item = (new EstimatePricingService())->price([$item])[0];

        $this->assertSame('candidate', $item['normative_match']['status']);
        $this->assertSame('not_calculated', $item['pricing_status']);
        $this->assertSame('scope_mismatch', $item['pricing_blocker']);
        $this->assertSame([], $item['materials']);
        $this->assertEquals(0.0, $item['total_cost']);
        $this->assertContains('scope_mismatch', $item['normative_match']['warnings']);
        $this->assertContains('safe_norm_required', $item['validation_flags']);
        $this->assertContains('pricing_not_calculated', $item['validation_flags']);
    }

    public function test_generic_foundation_work_does_not_accept_wall_masonry_norm(): void
    {
        $workItem = [
            'key' => 'foundation-generic',
            'name' => 'Foundation work',
            'description' => '',
            'unit' => 'm3',
            'quantity' => 10,
            'confidence' => 0.72,
            'validation_flags' => ['normative_required'],
            'materials' => [],
            'labor' => [],
            'machinery' => [],
        ];
        $match = [
            'version' => ['source_type' => 'fsnb_2022', 'version_key' => '2026-05-31'],
            'price_version' => ['source_type' => 'fsbc', 'version_key' => '2026-05-31'],
            'selected' => $this->wallMasonryCandidate(),
            'candidates' => [$this->wallMasonryCandidate()],
        ];

        $item = $this->manualSelectionService()->applySelectedNormativeMatch($workItem, $match, ['scope_type' => 'foundation']);
        $item = (new EstimatePricingService())->price([$item])[0];

        $this->assertSame('candidate', $item['normative_match']['status']);
        $this->assertSame('not_calculated', $item['pricing_status']);
        $this->assertSame('scope_mismatch', $item['pricing_blocker']);
        $this->assertEquals(0.0, $item['total_cost']);
        $this->assertContains('scope_mismatch', $item['normative_match']['warnings']);
        $this->assertContains('safe_norm_required', $item['validation_flags']);
    }

    public function test_foundation_waterproofing_can_accept_waterproofing_norm_from_section_08(): void
    {
        $workItem = [
            'key' => 'foundation-waterproofing',
            'name' => 'Foundation waterproofing',
            'unit' => 'm2',
            'quantity' => 50,
            'confidence' => 0.82,
            'validation_flags' => ['normative_required'],
            'materials' => [],
            'labor' => [],
            'machinery' => [],
            'work_intent' => [
                'scope' => 'foundation',
                'action' => 'waterproofing',
                'preferred_section_prefixes' => ['08', '12'],
                'forbidden_section_prefixes' => [],
            ],
        ];
        $match = [
            'version' => ['source_type' => 'fsnb_2022', 'version_key' => '2026-05-31'],
            'price_version' => ['source_type' => 'fsbc', 'version_key' => '2026-05-31'],
            'selected' => $this->foundationWaterproofingCandidate(),
            'candidates' => [$this->foundationWaterproofingCandidate()],
        ];

        $item = $this->manualSelectionService()->applySelectedNormativeMatch($workItem, $match, ['scope_type' => 'foundation']);
        $item = (new EstimatePricingService())->price([$item])[0];

        $this->assertSame('matched', $item['normative_match']['status']);
        $this->assertSame('calculated', $item['pricing_status']);
        $this->assertGreaterThan(0, $item['total_cost']);
        $this->assertNotContains('scope_mismatch', $item['normative_match']['warnings']);
    }

    public function test_manually_selected_review_priced_norm_keeps_review_warnings(): void
    {
        $workItem = [
            'key' => 'roof-insulation-manual',
            'name' => 'Утепление кровли 200 мм',
            'unit' => 'м2',
            'quantity' => 100,
            'confidence' => 0.7,
            'validation_flags' => ['normative_required'],
            'materials' => [],
            'labor' => [],
            'machinery' => [],
            'work_intent' => [
                'scope' => 'roof',
                'action' => 'insulation',
                'preferred_section_prefixes' => ['12', '26'],
                'forbidden_section_prefixes' => ['01', '16'],
            ],
        ];
        $match = [
            'version' => ['source_type' => 'fsnb_2022', 'version_key' => '2026-05-31'],
            'price_version' => ['source_type' => 'fsbc', 'version_key' => '2026-05-31'],
            'selected' => $this->safeReviewCandidate(),
            'candidates' => [$this->safeReviewCandidate()],
        ];

        $item = $this->manualSelectionService()->applySelectedNormativeMatch($workItem, $match, ['scope_type' => 'roof']);
        $item = (new EstimatePricingService())->price([$item])[0];

        $this->assertSame('matched', $item['normative_match']['status']);
        $this->assertTrue($item['normative_match']['selected_by_user']);
        $this->assertSame('review_priced', $item['normative_match']['decision']['status']);
        $this->assertSame('calculated_review_required', $item['pricing_status']);
        $this->assertGreaterThan(0, $item['total_cost']);
        $this->assertContains('requires_normative_review', $item['normative_match']['warnings']);
        $this->assertContains('safe_normative_analog', $item['normative_match']['warnings']);
        $this->assertContains('requires_normative_review', $item['validation_flags']);
        $this->assertContains('safe_normative_analog', $item['validation_flags']);
    }

    public function test_manually_selected_norm_keeps_existing_candidates_and_rejected_markers(): void
    {
        $workItem = [
            'key' => 'roof-insulation-manual',
            'name' => 'Roof insulation',
            'unit' => 'м2',
            'quantity' => 100,
            'confidence' => 0.7,
            'validation_flags' => ['normative_required'],
            'materials' => [],
            'labor' => [],
            'machinery' => [],
            'normative_candidates' => [[
                'norm_id' => 121,
                'code' => '12-01-014-01',
                'name' => 'Alternative roof insulation norm',
                'unit' => 'м2',
            ], [
                'norm_id' => 999,
                'code' => '01-02-057-01',
                'name' => 'Rejected catalog norm',
                'unit' => 'м3',
                'selection_source' => 'catalog_search',
                'user_feedback' => 'rejected',
                'rejected_by_user' => true,
                'warnings' => ['rejected_by_user'],
            ]],
            'work_intent' => [
                'scope' => 'roof',
                'action' => 'insulation',
                'preferred_section_prefixes' => ['12', '26'],
                'forbidden_section_prefixes' => ['01', '16'],
            ],
        ];
        $match = [
            'version' => ['source_type' => 'fsnb_2022', 'version_key' => '2026-05-31'],
            'price_version' => ['source_type' => 'fsbc', 'version_key' => '2026-05-31'],
            'selected' => $this->safeReviewCandidate(),
            'candidates' => [$this->safeReviewCandidate()],
        ];

        $item = $this->manualSelectionService()->applySelectedNormativeMatch($workItem, $match, ['scope_type' => 'roof']);
        $candidateCodes = array_column($item['normative_candidates'], 'code');
        $rejectedCandidate = array_values(array_filter(
            $item['normative_candidates'],
            static fn (array $candidate): bool => ($candidate['code'] ?? null) === '01-02-057-01'
        ))[0] ?? [];

        $this->assertContains('12-01-013-01', $candidateCodes);
        $this->assertContains('12-01-014-01', $candidateCodes);
        $this->assertContains('01-02-057-01', $candidateCodes);
        $this->assertTrue($rejectedCandidate['rejected_by_user'] ?? false);
        $this->assertSame('catalog_search', $rejectedCandidate['selection_source'] ?? null);
    }

    public function test_norm_with_partially_unpriced_resources_stays_unpriced_candidate(): void
    {
        $workItem = [
            'key' => 'foundation-concrete-partial-prices',
            'name' => 'Бетонирование фундамента',
            'unit' => 'м3',
            'quantity' => 10,
            'confidence' => 0.86,
            'validation_flags' => ['normative_required'],
            'materials' => [],
            'labor' => [],
            'machinery' => [],
            'work_intent' => [
                'scope' => 'foundation',
                'action' => 'concreting',
                'preferred_section_prefixes' => ['01', '06'],
                'forbidden_section_prefixes' => [],
            ],
        ];
        $match = [
            'version' => ['source_type' => 'fsnb_2022', 'version_key' => '2026-05-31'],
            'price_version' => ['source_type' => 'fsbc', 'version_key' => '2026-05-31'],
            'selected' => $this->partialPricesCandidate(),
            'candidates' => [$this->partialPricesCandidate()],
        ];

        $item = $this->manualSelectionService()->applySelectedNormativeMatch($workItem, $match, ['scope_type' => 'foundation']);
        $item = (new EstimatePricingService())->price([$item])[0];

        $this->assertSame('candidate', $item['normative_match']['status']);
        $this->assertSame('not_calculated', $item['pricing_status']);
        $this->assertSame('norm_with_unpriced_resources', $item['pricing_blocker']);
        $this->assertEquals(0.0, $item['total_cost']);
        $this->assertContains('norm_with_unpriced_resources', $item['normative_match']['warnings']);
        $this->assertContains('safe_norm_required', $item['validation_flags']);
        $this->assertContains('pricing_not_calculated', $item['validation_flags']);
    }

    public function test_norm_with_zero_price_source_stays_unpriced_candidate(): void
    {
        $workItem = [
            'key' => 'foundation-concrete-zero-price',
            'name' => 'Бетонирование фундамента',
            'unit' => 'м3',
            'quantity' => 10,
            'confidence' => 0.86,
            'validation_flags' => ['normative_required'],
            'materials' => [],
            'labor' => [],
            'machinery' => [],
            'work_intent' => [
                'scope' => 'foundation',
                'action' => 'concreting',
                'preferred_section_prefixes' => ['01', '06'],
                'forbidden_section_prefixes' => [],
            ],
        ];
        $match = [
            'version' => ['source_type' => 'fsnb_2022', 'version_key' => '2026-05-31'],
            'price_version' => ['source_type' => 'fsbc', 'version_key' => '2026-05-31'],
            'selected' => $this->zeroPriceSourceCandidate(),
            'candidates' => [$this->zeroPriceSourceCandidate()],
        ];

        $item = $this->manualSelectionService()->applySelectedNormativeMatch($workItem, $match, ['scope_type' => 'foundation']);
        $item = (new EstimatePricingService())->price([$item])[0];

        $this->assertSame('candidate', $item['normative_match']['status']);
        $this->assertSame('not_calculated', $item['pricing_status']);
        $this->assertSame('normative_resources_or_prices_missing', $item['pricing_blocker']);
        $this->assertEquals(0.0, $item['total_cost']);
        $this->assertContains('norm_without_prices', $item['normative_match']['warnings']);
        $this->assertContains('safe_norm_required', $item['validation_flags']);
        $this->assertContains('pricing_not_calculated', $item['validation_flags']);
    }

    private function manualSelectionService(): ResourceAssemblyService
    {
        return new ResourceAssemblyService(
            $this->createMock(EstimateNormativeMatcher::class),
            new NormativeMatchDecisionService(),
            new NormativeCandidatePresenter(),
            new WorkIntentClassifier(new NormativeScopeRuleCatalog()),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function unsafeCandidate(): array
    {
        return [
            'key' => 'norm-100',
            'norm_id' => 100,
            'code' => '01-01-063-01',
            'name' => 'Разработка грунта в траншеях',
            'unit' => 'км',
            'collection' => ['code' => 'gesn', 'name' => 'ГЭСН', 'norm_type' => 'gesn'],
            'section' => ['code' => '01-01', 'name' => 'Земляные работы'],
            'score' => 90,
            'confidence' => 0.9,
            'match_reasons' => ['name'],
            'warnings' => [],
            'work_composition' => ['Разработка грунта'],
            'resources' => [
                'materials' => [[
                    'code' => '01.1.01.01-0001',
                    'name' => 'Песок',
                    'resource_type' => 'material',
                    'unit' => 'м3',
                    'quantity' => 1.0,
                    'unit_price' => 1000.0,
                    'total_price' => 1000.0,
                    'price_source' => 'fsbc_base',
                    'price_id' => 1,
                    'linked_resource_id' => null,
                ]],
                'labor' => [],
                'machinery' => [],
                'other' => [],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function safeReviewCandidate(): array
    {
        return [
            'key' => 'norm-120',
            'norm_id' => 120,
            'code' => '12-01-013-01',
            'name' => 'Утепление покрытий кровли минераловатными плитами',
            'unit' => 'м2',
            'collection' => ['code' => 'gesn', 'name' => 'ГЭСН', 'norm_type' => 'gesn'],
            'section' => ['code' => '12-01', 'name' => 'Кровли'],
            'score' => 64,
            'confidence' => 0.61,
            'match_reasons' => ['unit', 'search_profile_section'],
            'warnings' => [],
            'work_composition' => ['Укладка плит утеплителя'],
            'resources' => [
                'materials' => [[
                    'code' => '12.1.01.01-0001',
                    'name' => 'Плиты минераловатные',
                    'resource_type' => 'material',
                    'unit' => 'м2',
                    'quantity' => 1.05,
                    'unit_price' => 800.0,
                    'total_price' => 840.0,
                    'price_source' => 'fsbc_base',
                    'price_id' => 1,
                    'linked_resource_id' => null,
                ]],
                'labor' => [],
                'machinery' => [],
                'other' => [],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function earthSquareCandidate(): array
    {
        return [
            'key' => 'norm-101',
            'norm_id' => 101,
            'code' => '01-01-011-01',
            'name' => 'Разработка грунта',
            'unit' => 'м2',
            'collection' => ['code' => 'gesn', 'name' => 'ГЭСН', 'norm_type' => 'gesn'],
            'section' => ['code' => '01-01', 'name' => 'Земляные работы'],
            'score' => 91,
            'confidence' => 0.93,
            'match_reasons' => ['unit'],
            'warnings' => [],
            'work_composition' => ['Разработка грунта'],
            'resources' => [
                'materials' => [[
                    'code' => '01.1.01.01-0001',
                    'name' => 'Песок',
                    'resource_type' => 'material',
                    'unit' => 'м3',
                    'quantity' => 1.0,
                    'unit_price' => 1000.0,
                    'total_price' => 1000.0,
                    'price_source' => 'fsbc_base',
                    'price_id' => 1,
                    'linked_resource_id' => null,
                ]],
                'labor' => [],
                'machinery' => [],
                'other' => [],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function wallMasonryCandidate(): array
    {
        return [
            'key' => 'norm-150',
            'norm_id' => 150,
            'code' => '08-02-001-01',
            'name' => 'Masonry walls',
            'unit' => 'm3',
            'collection' => ['code' => 'gesn', 'name' => 'ГЭСН', 'norm_type' => 'gesn'],
            'section' => ['code' => '08-02', 'name' => 'Walls'],
            'score' => 98,
            'confidence' => 0.94,
            'match_reasons' => ['manual_catalog_selection'],
            'warnings' => [],
            'work_composition' => ['Wall masonry'],
            'resources' => [
                'materials' => [[
                    'code' => '08.1.01.01-0001',
                    'name' => 'Blocks',
                    'resource_type' => 'material',
                    'unit' => 'm3',
                    'quantity' => 1.0,
                    'unit_price' => 3000.0,
                    'total_price' => 3000.0,
                    'price_source' => 'fsbc_base',
                    'price_id' => 1,
                    'linked_resource_id' => null,
                ]],
                'labor' => [],
                'machinery' => [],
                'other' => [],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function foundationWaterproofingCandidate(): array
    {
        return [
            'key' => 'norm-151',
            'norm_id' => 151,
            'code' => '08-01-003-01',
            'name' => 'Foundation waterproofing',
            'unit' => 'm2',
            'collection' => ['code' => 'gesn', 'name' => 'ГЭСН', 'norm_type' => 'gesn'],
            'section' => ['code' => '08-01', 'name' => 'Waterproofing'],
            'score' => 96,
            'confidence' => 0.91,
            'match_reasons' => ['manual_catalog_selection'],
            'warnings' => [],
            'work_composition' => ['Waterproofing'],
            'resources' => [
                'materials' => [[
                    'code' => '08.1.01.03-0001',
                    'name' => 'Membrane',
                    'resource_type' => 'material',
                    'unit' => 'm2',
                    'quantity' => 1.0,
                    'unit_price' => 500.0,
                    'total_price' => 500.0,
                    'price_source' => 'fsbc_base',
                    'price_id' => 1,
                    'linked_resource_id' => null,
                ]],
                'labor' => [],
                'machinery' => [],
                'other' => [],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function partialPricesCandidate(): array
    {
        return [
            'key' => 'norm-202',
            'norm_id' => 202,
            'code' => '06-01-001-01',
            'name' => 'Бетонирование фундамента',
            'unit' => 'м3',
            'collection' => ['code' => 'gesn', 'name' => 'ГЭСН', 'norm_type' => 'gesn'],
            'section' => ['code' => '06-01', 'name' => 'Бетонные работы'],
            'score' => 96,
            'confidence' => 0.91,
            'match_reasons' => ['exact_name', 'unit', 'resources', 'prices'],
            'warnings' => [],
            'work_composition' => ['Укладка бетонной смеси'],
            'resources' => [
                'materials' => [[
                    'code' => '06.1.01.01-0001',
                    'name' => 'Бетон тяжелый',
                    'resource_type' => 'material',
                    'unit' => 'м3',
                    'quantity' => 1.0,
                    'unit_price' => 5000.0,
                    'total_price' => 5000.0,
                    'price_source' => 'fsbc_base',
                    'price_id' => 10,
                    'linked_resource_id' => 20,
                ], [
                    'code' => '06.1.01.01-9999',
                    'name' => 'Добавка к бетону',
                    'resource_type' => 'material',
                    'unit' => 'кг',
                    'quantity' => 2.5,
                    'unit_price' => 0.0,
                    'total_price' => 0.0,
                    'price_source' => null,
                    'price_id' => null,
                    'linked_resource_id' => 21,
                ]],
                'labor' => [],
                'machinery' => [],
                'other' => [],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function zeroPriceSourceCandidate(): array
    {
        return [
            'key' => 'norm-203',
            'norm_id' => 203,
            'code' => '06-01-002-01',
            'name' => 'Бетонирование ростверка',
            'unit' => 'м3',
            'collection' => ['code' => 'gesn', 'name' => 'ГЭСН', 'norm_type' => 'gesn'],
            'section' => ['code' => '06-01', 'name' => 'Бетонные работы'],
            'score' => 96,
            'confidence' => 0.91,
            'match_reasons' => ['exact_name', 'unit', 'resources', 'prices'],
            'warnings' => [],
            'work_composition' => ['Укладка бетонной смеси'],
            'resources' => [
                'materials' => [[
                    'code' => '06.1.01.01-0002',
                    'name' => 'Бетон тяжелый',
                    'resource_type' => 'material',
                    'unit' => 'м3',
                    'quantity' => 1.0,
                    'unit_price' => 0.0,
                    'total_price' => 0.0,
                    'price_source' => 'fsbc_base',
                    'price_id' => 11,
                    'linked_resource_id' => 22,
                ]],
                'labor' => [],
                'machinery' => [],
                'other' => [],
            ],
        ];
    }
}
