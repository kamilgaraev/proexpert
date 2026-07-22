<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackage;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackageItem;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationPackagePresenter;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class EstimateGenerationPackagePresenterTest extends TestCase
{
    public function test_package_summary_exposes_human_readable_coverage_warnings(): void
    {
        $package = new EstimateGenerationPackage([
            'id' => 7,
            'key' => 'stairs',
            'title' => 'Лестницы',
            'totals' => [],
            'metadata' => [
                'coverage_warnings' => [[
                    'quantity_key' => 'stairs.flights',
                    'reason' => 'stair_construction_geometry_missing',
                    'package_key' => 'stairs',
                    'message' => 'Лестница не включена: в документах нет размеров.',
                ]],
            ],
        ]);

        $payload = (new EstimateGenerationPackagePresenter)->summary($package);

        self::assertSame(
            'Лестница не включена: в документах нет размеров.',
            $payload['coverage_warnings'][0]['message'] ?? null,
        );
    }

    public function test_package_summary_translates_a_generated_missing_normative_candidate_warning(): void
    {
        $translations = require dirname(__DIR__, 3).'/lang/ru/estimate_generation.php';
        $previousContainer = Container::getInstance();
        $previousFacadeApplication = Facade::getFacadeApplication();
        $container = new Container;
        $container->instance('app', new class
        {
            public function getLocale(): string
            {
                return 'ru';
            }
        });
        $container->instance('config', new Repository(['app' => ['fallback_locale' => 'ru']]));
        $container->instance('translator', new class($translations)
        {
            public function __construct(private array $translations) {}

            public function get(string $key): string
            {
                $reason = str_replace('estimate_generation.quantity_coverage_warnings.', '', $key);

                return $this->translations['quantity_coverage_warnings'][$reason] ?? $key;
            }
        });
        $container->instance('log', new NullLogger);
        Container::setInstance($container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);

        try {
            $package = new EstimateGenerationPackage([
                'id' => 8,
                'key' => 'roof',
                'title' => 'Кровля',
                'totals' => [],
                'metadata' => [
                    'coverage_warnings' => [[
                        'quantity_key' => 'roof.covering',
                        'reason' => 'normative_candidate_missing',
                        'package_key' => 'roof',
                    ]],
                ],
            ]);

            $payload = (new EstimateGenerationPackagePresenter)->summary($package);

            self::assertSame([[
                'quantity_key' => 'roof.covering',
                'reason' => 'normative_candidate_missing',
                'package_key' => 'roof',
                'message' => $translations['quantity_coverage_warnings']['normative_candidate_missing'],
            ]], $payload['coverage_warnings']);
        } finally {
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($previousFacadeApplication);
            Container::setInstance($previousContainer);
        }
    }

    #[Test]
    public function test_item_preserves_exact_quantity_as_canonical_string(): void
    {
        $item = new EstimateGenerationPackageItem;
        $item->forceFill([
            'id' => 1, 'key' => 'exact', 'logical_key' => 'exact', 'revision' => 1,
            'item_type' => 'priced_work', 'name' => 'Работа', 'unit' => 'м2',
            'quantity' => '123456789.123456789123456789', 'total_cost' => '1.00',
            'metadata' => [], 'flags' => [], 'resources' => [],
        ]);

        self::assertSame(
            '123456789.123456789123456789',
            (new EstimateGenerationPackagePresenter)->item($item)['quantity'],
        );
    }

    public function test_package_detail_item_exposes_normative_candidates_for_inline_selection(): void
    {
        $item = new EstimateGenerationPackageItem([
            'id' => 15,
            'key' => 'earth.backfill',
            'level' => 0,
            'item_type' => 'priced_work',
            'name' => 'Обратная засыпка пазух',
            'unit' => 'м3',
            'quantity' => 42.5,
            'quantity_basis' => ['description' => 'Количество требует проверки'],
            'price_source' => null,
            'normative_status' => 'candidate',
            'normative_confidence' => 0.61,
            'unit_price' => 0,
            'direct_cost' => 0,
            'overhead_cost' => 0,
            'profit_cost' => 0,
            'total_cost' => 0,
            'resources' => [],
            'flags' => ['pricing_not_calculated'],
            'metadata' => [
                'work_category' => 'earthworks',
                'normative_match' => [
                    'status' => 'candidate',
                    'confidence' => 0.61,
                ],
                'normative_candidates' => [[
                    'norm_id' => 101,
                    'code' => '01-02-057-01',
                    'name' => 'Обратная засыпка грунта',
                    'unit' => 'м3',
                    'confidence' => 0.82,
                    'resources_count' => 5,
                    'priced_resources_count' => 5,
                ]],
                'source_refs' => [[
                    'type' => 'drawing',
                    'filename' => 'plan.pdf',
                    'page_number' => 1,
                ]],
            ],
            'sort_order' => 100,
        ]);

        $payload = (new EstimateGenerationPackagePresenter)->item($item);

        self::assertSame('candidate', $payload['normative_match']['status'] ?? null);
        self::assertSame(101, $payload['normative_candidates'][0]['norm_id'] ?? null);
        self::assertSame('01-02-057-01', $payload['normative_candidates'][0]['code'] ?? null);
        self::assertSame('earthworks', $payload['work_category'] ?? null);
        self::assertSame('pricing_not_calculated', $payload['validation_flags'][0] ?? null);
        self::assertSame('drawing', $payload['source_refs'][0]['type'] ?? null);
    }

    public function test_zero_total_item_does_not_use_stale_calculated_metadata_status(): void
    {
        $item = new EstimateGenerationPackageItem([
            'id' => 16,
            'key' => 'foundation.zero',
            'item_type' => 'priced_work',
            'name' => 'Foundation zero price',
            'unit' => 'm3',
            'quantity' => 1,
            'price_source' => null,
            'unit_price' => 0,
            'direct_cost' => 0,
            'overhead_cost' => 0,
            'profit_cost' => 0,
            'total_cost' => 0,
            'resources' => [],
            'flags' => [],
            'metadata' => [
                'pricing_status' => 'calculated',
            ],
        ]);

        $payload = (new EstimateGenerationPackagePresenter)->item($item);

        self::assertSame('not_calculated', $payload['pricing_status']);
        self::assertSame('pricing_not_calculated', $payload['pricing_blocker']);
    }

    public function test_package_collection_counts_review_required_separately_from_ready(): void
    {
        $payload = (new EstimateGenerationPackagePresenter)->collection(new Collection([
            new EstimateGenerationPackage(['status' => 'ready_for_review', 'totals' => []]),
            new EstimateGenerationPackage(['status' => 'review_required', 'totals' => []]),
            new EstimateGenerationPackage(['status' => 'blocked', 'totals' => []]),
        ]));

        self::assertSame(1, $payload['summary']['ready']);
        self::assertSame(1, $payload['summary']['review_required']);
        self::assertSame(1, $payload['summary']['blocked']);
    }

    public function test_package_detail_hides_service_rows_from_estimate_positions(): void
    {
        $package = new EstimateGenerationPackage([
            'id' => 7,
            'key' => 'foundation',
            'title' => 'Фундамент',
            'status' => 'review_required',
            'actual_items_count' => 3,
            'totals' => [
                'total_cost' => 0,
                'priced_items_count' => 1,
                'operation_items_count' => 1,
                'review_notes_count' => 1,
            ],
        ]);

        $items = new Collection([
            new EstimateGenerationPackageItem([
                'id' => 10,
                'key' => 'foundation.concrete',
                'item_type' => 'priced_work',
                'name' => 'Бетонирование фундаментов',
                'total_cost' => 0,
                'metadata' => ['normative_candidates' => []],
                'flags' => ['requires_normative_review'],
            ]),
            new EstimateGenerationPackageItem([
                'id' => 11,
                'key' => 'foundation.operation',
                'item_type' => 'operation',
                'name' => 'Подготовка фронта работ',
            ]),
            new EstimateGenerationPackageItem([
                'id' => 12,
                'key' => 'foundation.note',
                'item_type' => 'review_note',
                'name' => 'Требует проверки',
            ]),
        ]);

        $payload = (new EstimateGenerationPackagePresenter)->detail($package, $items);

        self::assertCount(1, $payload['items']);
        self::assertSame('foundation.concrete', $payload['items'][0]['key']);
        self::assertSame(1, $payload['package']['actual_items_count']);
        self::assertSame(1, $payload['package']['totals']['items_count']);
        self::assertSame(1, $payload['package']['totals']['total_items_count']);
        self::assertSame(1, $payload['package']['totals']['priced_items_count']);
        self::assertSame(0, $payload['package']['totals']['operation_items_count']);
        self::assertSame(0, $payload['package']['totals']['review_notes_count']);
        self::assertSame(2, $payload['package']['totals']['hidden_service_items_count']);
        self::assertSame(1, $payload['package']['items_breakdown']['total']);
        self::assertSame(0, $payload['package']['items_breakdown']['operations']);
        self::assertSame(2, $payload['package']['items_breakdown']['hidden_service_items']);
        self::assertSame(1, $payload['meta']['items_count']);
        self::assertSame(0, $payload['meta']['operation_items_count']);
        self::assertSame(2, $payload['meta']['hidden_service_items_count']);
    }

    public function test_package_detail_exposes_only_latest_revision_per_logical_key(): void
    {
        $package = new EstimateGenerationPackage(['id' => 7, 'key' => 'foundation', 'status' => 'ready_for_review', 'totals' => []]);
        $items = new Collection([
            new EstimateGenerationPackageItem(['id' => 10, 'key' => 'work#r1', 'logical_key' => 'work', 'revision' => 1, 'item_type' => 'priced_work', 'name' => 'Old', 'total_cost' => 10]),
            new EstimateGenerationPackageItem(['id' => 11, 'key' => 'work#r2', 'logical_key' => 'work', 'revision' => 2, 'supersedes_item_id' => 10, 'item_type' => 'priced_work', 'name' => 'Current', 'total_cost' => 20]),
        ]);

        $payload = (new EstimateGenerationPackagePresenter)->detail($package, $items);

        self::assertSame(1, $payload['meta']['items_count']);
        self::assertSame('work', $payload['items'][0]['key']);
        self::assertSame('work#r2', $payload['items'][0]['physical_key']);
        self::assertSame(2, $payload['items'][0]['revision']);
        self::assertSame(10, $payload['items'][0]['supersedes_item_id']);
        self::assertSame('Current', $payload['items'][0]['name']);
    }
}
