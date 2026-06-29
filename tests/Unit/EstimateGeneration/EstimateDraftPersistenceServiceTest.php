<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateDraftPersistenceService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationNoAirWorkItemPolicy;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackage;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackageItem;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidationFactory;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

final class EstimateDraftPersistenceServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container();
        $loader = new FileLoader(new Filesystem(), dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'lang');
        $translator = new Translator($loader, 'ru');

        $container->instance('app', new class {
            public function getLocale(): string
            {
                return 'ru';
            }
        });
        $container->instance('config', new Repository(['app' => ['fallback_locale' => 'ru']]));
        $container->instance('translator', $translator);
        $container->instance('validator', new ValidationFactory($translator, $container));

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

    public function test_review_required_quality_status_blocks_apply_guard(): void
    {
        $blocker = (new TestableEstimateDraftPersistenceService())->blockerFor([
            'quality_summary' => [
                'status' => 'review_required',
                'not_calculated_work_items' => 0,
                'safe_norm_required_work_items' => 0,
                'normative_items' => [
                    'requires_review' => 0,
                    'review_priced' => 1,
                ],
            ],
        ]);

        self::assertSame(['type' => 'prices_require_review'], $blocker);
    }

    public function test_service_rows_are_not_persistable_final_estimate_items(): void
    {
        $service = new TestableEstimateDraftPersistenceService();
        $persistable = $service->persistableItemsFor([
            $this->workItem('work-1', 'priced_work', 1000),
            $this->workItem('operation-1', 'operation', 0),
            $this->workItem('note-1', 'resource_note', 0),
            $this->workItem('review-1', 'review_note', 0),
            $this->workItem('not-calculated-1', 'priced_work', 0, 'not_calculated'),
            $this->workItem('review-priced-1', 'priced_work', 1000, 'calculated_review_required'),
        ]);

        self::assertCount(1, $persistable);
        self::assertSame('work-1', $persistable[0]['key']);
    }

    public function test_generic_priced_work_is_not_persistable_final_estimate_item(): void
    {
        $service = new TestableEstimateDraftPersistenceService();
        $persistable = $service->persistableItemsFor([
            [
                'key' => 'generic-complex-work',
                'item_type' => 'priced_work',
                'name' => 'Комплекс строительных работ',
                'normative_search_text' => 'Комплекс строительных работ',
                'unit' => 'компл',
                'quantity' => 1,
                'total_cost' => 250000,
                'pricing_status' => 'calculated',
                'normative_rate_code' => '01-01-001-01',
            ],
            $this->workItem('work-1', 'priced_work', 1000),
        ]);

        self::assertCount(1, $persistable);
        self::assertSame('work-1', $persistable[0]['key']);
    }

    public function test_persistable_total_ignores_service_and_not_calculated_rows(): void
    {
        $service = new TestableEstimateDraftPersistenceService();
        $total = $service->persistableTotalFor([
            'local_estimates' => [[
                'sections' => [[
                    'work_items' => [
                        $this->workItem('work-1', 'priced_work', 1000),
                        $this->workItem('work-2', 'priced_work', 2500),
                        $this->workItem('operation-1', 'operation', 0),
                        $this->workItem('not-calculated-1', 'priced_work', 0, 'not_calculated'),
                    ],
                ]],
            ]],
        ]);

        self::assertSame(3500.0, $total);
    }

    public function test_duplicate_metric_blocks_apply_even_when_quality_status_is_ready(): void
    {
        $blocker = (new TestableEstimateDraftPersistenceService())->blockerFor([
            'quality_summary' => [
                'status' => 'ready',
                'duplicate_work_items' => 2,
                'not_calculated_work_items' => 0,
                'safe_norm_required_work_items' => 0,
                'normative_items' => [
                    'requires_review' => 0,
                ],
            ],
        ]);

        self::assertSame(['type' => 'prices_require_review'], $blocker);
    }

    public function test_non_persistable_priced_work_blocks_apply_even_when_quality_status_is_ready(): void
    {
        $blocker = (new TestableEstimateDraftPersistenceService())->blockerFor([
            'quality_summary' => [
                'status' => 'ready',
                'not_calculated_work_items' => 0,
                'safe_norm_required_work_items' => 0,
                'normative_items' => [
                    'requires_review' => 0,
                ],
            ],
            'local_estimates' => [[
                'sections' => [[
                    'work_items' => [
                        $this->workItem('not-calculated-1', 'priced_work', 0, 'not_calculated'),
                    ],
                ]],
            ]],
        ]);

        self::assertSame(['type' => 'prices_require_review'], $blocker);
    }

    public function test_missing_normative_code_blocks_apply_even_with_positive_price(): void
    {
        $blocker = (new TestableEstimateDraftPersistenceService())->blockerFor([
            'quality_summary' => [
                'status' => 'ready',
                'not_calculated_work_items' => 0,
                'safe_norm_required_work_items' => 0,
                'normative_items' => [
                    'requires_review' => 0,
                ],
            ],
            'local_estimates' => [[
                'sections' => [[
                    'work_items' => [
                        $this->workItem('work-without-norm', 'priced_work', 1000, 'calculated', null),
                    ],
                ]],
            ]],
        ]);

        self::assertSame(['type' => 'prices_require_review'], $blocker);
    }

    public function test_generic_priced_work_blocks_apply_even_when_quality_status_is_ready(): void
    {
        $blocker = (new TestableEstimateDraftPersistenceService())->blockerFor([
            'quality_summary' => [
                'status' => 'ready',
                'not_calculated_work_items' => 0,
                'safe_norm_required_work_items' => 0,
                'normative_items' => [
                    'requires_review' => 0,
                ],
            ],
            'local_estimates' => [[
                'sections' => [[
                    'work_items' => [
                        [
                            'key' => 'generic-complex-work',
                            'item_type' => 'priced_work',
                            'name' => 'Комплекс строительных работ',
                            'unit' => 'компл',
                            'quantity' => 1,
                            'total_cost' => 250000,
                            'pricing_status' => 'calculated',
                            'normative_rate_code' => '01-01-001-01',
                            'validation_flags' => [EstimateGenerationNoAirWorkItemPolicy::FLAG],
                        ],
                    ],
                ]],
            ]],
        ]);

        self::assertSame(['type' => 'prices_require_review'], $blocker);
    }

    public function test_empty_persistable_total_blocks_apply_even_when_quality_status_is_ready(): void
    {
        $blocker = (new TestableEstimateDraftPersistenceService())->blockerFor([
            'quality_summary' => [
                'status' => 'ready',
                'not_calculated_work_items' => 0,
                'safe_norm_required_work_items' => 0,
                'normative_items' => [
                    'requires_review' => 0,
                ],
            ],
            'local_estimates' => [[
                'sections' => [[
                    'work_items' => [
                        $this->workItem('operation-1', 'operation', 0),
                    ],
                ]],
            ]],
        ]);

        self::assertSame(['type' => 'prices_require_review'], $blocker);
    }

    public function test_blocking_review_queue_item_blocks_apply_even_when_quality_status_is_ready(): void
    {
        $package = new EstimateGenerationPackage([
            'key' => 'local-1',
            'title' => 'Local estimate',
            'scope_type' => 'site',
            'source_refs' => [],
        ]);
        $package->setRelation('items', collect([
            new EstimateGenerationPackageItem([
                'key' => 'package-only-blocker',
                'item_type' => 'priced_work',
                'name' => 'Package only blocker',
                'unit' => 'm',
                'quantity' => 1,
                'total_cost' => 0,
                'flags' => ['pricing_not_calculated'],
                'metadata' => [
                    'pricing_status' => 'not_calculated',
                    'pricing_blocker' => 'normative_required',
                    'normative_match' => ['status' => 'not_found'],
                ],
            ]),
        ]));
        $session = new EstimateGenerationSession([
            'draft_payload' => [
                'quality_summary' => [
                    'status' => 'ready',
                    'not_calculated_work_items' => 0,
                    'safe_norm_required_work_items' => 0,
                    'normative_items' => [
                        'requires_review' => 0,
                    ],
                ],
                'local_estimates' => [[
                    'key' => 'local-1',
                    'sections' => [[
                        'work_items' => [
                            $this->workItem('ready-work', 'priced_work', 1000),
                        ],
                    ]],
                ]],
            ],
        ]);
        $session->setRelation('packages', collect([$package]));

        $this->expectException(ValidationException::class);

        (new TestableEstimateDraftPersistenceService())->assertNoBlockingReviewItemsFor($session);
    }

    /**
     * @return array<string, mixed>
     */
    private function workItem(
        string $key,
        string $type,
        float $totalCost,
        string $pricingStatus = 'calculated',
        ?string $normativeRateCode = '01-01-001-01'
    ): array
    {
        $workItem = [
            'key' => $key,
            'item_type' => $type,
            'quantity' => $totalCost > 0 ? 1 : 0,
            'total_cost' => $totalCost,
            'pricing_status' => $pricingStatus,
        ];

        if ($normativeRateCode !== null) {
            $workItem['normative_rate_code'] = $normativeRateCode;
        }

        return $workItem;
    }
}

final class TestableEstimateDraftPersistenceService extends EstimateDraftPersistenceService
{
    public function assertNoBlockingReviewItemsFor(EstimateGenerationSession $session): void
    {
        $this->assertNoBlockingReviewItems($session);
    }

    /**
     * @param array<string, mixed> $draft
     */
    public function blockerFor(array $draft): ?array
    {
        return $this->applyBlocker($draft);
    }

    /**
     * @param array<int, mixed> $workItems
     * @return array<int, array<string, mixed>>
     */
    public function persistableItemsFor(array $workItems): array
    {
        return $this->persistableWorkItems($workItems);
    }

    /**
     * @param array<string, mixed> $draft
     */
    public function persistableTotalFor(array $draft): float
    {
        return $this->persistableDraftTotal($draft);
    }
}
