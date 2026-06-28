<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackageItem;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationPackagePresenter;
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
}
