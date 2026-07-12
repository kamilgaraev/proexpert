<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackage;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackageItem;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationPackagePresenter;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;

final class EstimateGenerationPackagePresenterTest extends TestCase
{
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

        $payload = (new EstimateGenerationPackagePresenter())->item($item);

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

        $payload = (new EstimateGenerationPackagePresenter())->item($item);

        self::assertSame('not_calculated', $payload['pricing_status']);
        self::assertSame('pricing_not_calculated', $payload['pricing_blocker']);
    }

    public function test_package_collection_counts_review_required_separately_from_ready(): void
    {
        $payload = (new EstimateGenerationPackagePresenter())->collection(new Collection([
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

        $payload = (new EstimateGenerationPackagePresenter())->detail($package, $items);

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
