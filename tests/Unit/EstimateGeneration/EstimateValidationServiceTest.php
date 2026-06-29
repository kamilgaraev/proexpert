<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateValidationService;
use PHPUnit\Framework\TestCase;

final class EstimateValidationServiceTest extends TestCase
{
    public function test_zero_price_priced_work_is_marked_not_calculated(): void
    {
        $draft = $this->service()->validate($this->draft([
            [
                'key' => 'work-1',
                'item_type' => 'priced_work',
                'name' => 'Монтаж трубопровода',
                'unit' => 'м',
                'quantity' => 12,
                'quantity_basis' => 'По спецификации, стр. 2',
                'total_cost' => 0,
                'materials' => [],
                'labor' => [],
                'machinery' => [],
                'pricing_status' => 'calculated',
                'normative_match' => [
                    'status' => 'matched',
                    'decision' => ['status' => 'accepted'],
                ],
                'validation_flags' => [],
                'confidence' => 0.86,
            ],
        ]));

        $item = $draft['local_estimates'][0]['sections'][0]['work_items'][0];

        self::assertSame('not_calculated', $item['pricing_status']);
        self::assertSame('normative_resources_or_prices_missing', $item['pricing_blocker']);
        self::assertContains('missing_price', $item['validation_flags']);
        self::assertContains('missing_resources', $item['validation_flags']);
        self::assertContains('pricing_not_calculated', $item['validation_flags']);
        self::assertSame(0, $draft['quality_summary']['priced_work_items']);
        self::assertSame(1, $draft['quality_summary']['zero_price_work_items']);
        self::assertSame(1, $draft['quality_summary']['not_calculated_work_items']);
        self::assertSame('critical', $draft['quality_summary']['status']);
    }

    public function test_positive_priced_work_with_resources_stays_calculated(): void
    {
        $draft = $this->service()->validate($this->draft([
            [
                'key' => 'work-1',
                'item_type' => 'priced_work',
                'name' => 'Бетонирование конструкций',
                'unit' => 'м3',
                'quantity' => 8,
                'quantity_basis' => 'Ведомость объемов, стр. 1',
                'total_cost' => 120000,
                'materials' => [['total_price' => 80000]],
                'labor' => [['total_price' => 25000]],
                'machinery' => [['total_price' => 15000]],
                'pricing_status' => 'calculated',
                'normative_match' => [
                    'status' => 'matched',
                    'decision' => ['status' => 'accepted'],
                ],
                'validation_flags' => [],
                'confidence' => 0.92,
            ],
        ]));

        $item = $draft['local_estimates'][0]['sections'][0]['work_items'][0];

        self::assertSame('calculated', $item['pricing_status']);
        self::assertNull($item['pricing_blocker']);
        self::assertSame(1, $draft['quality_summary']['priced_work_items']);
        self::assertSame(0, $draft['quality_summary']['zero_price_work_items']);
        self::assertSame(0, $draft['quality_summary']['not_calculated_work_items']);
    }

    public function test_auto_review_priced_normative_match_still_requires_manual_review(): void
    {
        $draft = $this->service()->validate($this->draft([
            [
                'key' => 'work-1',
                'item_type' => 'priced_work',
                'name' => 'РњРѕРЅС‚Р°Р¶ С‚СЂСѓР±РѕРїСЂРѕРІРѕРґР°',
                'unit' => 'Рј',
                'quantity' => 12,
                'quantity_basis' => 'РџРѕ СЃРїРµС†РёС„РёРєР°С†РёРё, СЃС‚СЂ. 2',
                'total_cost' => 12000,
                'materials' => [['total_price' => 8000]],
                'labor' => [['total_price' => 3000]],
                'machinery' => [['total_price' => 1000]],
                'pricing_status' => 'calculated_review_required',
                'normative_match' => [
                    'status' => 'matched',
                    'selected_by_user' => false,
                    'decision' => ['status' => 'review_priced'],
                ],
                'validation_flags' => ['requires_normative_review', 'safe_normative_analog'],
                'confidence' => 0.76,
            ],
        ]));

        $item = $draft['local_estimates'][0]['sections'][0]['work_items'][0];

        self::assertSame('calculated_review_required', $item['pricing_status']);
        self::assertSame(1, $draft['quality_summary']['priced_work_items']);
        self::assertSame(0, $draft['quality_summary']['not_calculated_work_items']);
        self::assertSame(1, $draft['quality_summary']['normative_items']['review_priced']);
        self::assertSame(1, $draft['quality_summary']['normative_items']['requires_review']);
        self::assertSame('review_required', $draft['quality_summary']['status']);
    }

    public function test_duplicate_priced_work_items_require_manual_review(): void
    {
        $workItem = [
            'item_type' => 'priced_work',
            'name' => 'Concrete works',
            'normative_search_text' => 'concrete works',
            'normative_search_key' => 'foundation|concrete|m3',
            'unit' => 'm3',
            'quantity' => 8,
            'quantity_basis' => 'Drawing A101, page 1',
            'total_cost' => 120000,
            'materials' => [['total_price' => 80000]],
            'labor' => [['total_price' => 25000]],
            'machinery' => [['total_price' => 15000]],
            'pricing_status' => 'calculated',
            'normative_match' => [
                'status' => 'matched',
                'decision' => ['status' => 'accepted'],
            ],
            'source_refs' => [['document_id' => 1, 'page_number' => 1, 'takeoff_id' => 10]],
            'validation_flags' => [],
            'confidence' => 0.92,
        ];

        $draft = $this->service()->validate($this->draft([
            ['key' => 'work-1', ...$workItem],
            ['key' => 'work-2', ...$workItem],
        ]));

        $firstItem = $draft['local_estimates'][0]['sections'][0]['work_items'][0];
        $secondItem = $draft['local_estimates'][0]['sections'][0]['work_items'][1];

        self::assertContains('possible_duplicate_work_item', $firstItem['validation_flags']);
        self::assertContains('possible_duplicate_work_item', $secondItem['validation_flags']);
        self::assertContains('requires_duplicate_review', $draft['problem_flags']);
        self::assertSame(2, $draft['quality_summary']['duplicate_work_items']);
        self::assertSame('review_required', $draft['quality_summary']['status']);
    }

    /**
     * @param array<int, array<string, mixed>> $workItems
     * @return array<string, mixed>
     */
    private function draft(array $workItems): array
    {
        return [
            'local_estimates' => [
                [
                    'key' => 'local-1',
                    'title' => 'Локальная смета',
                    'source_refs' => [['type' => 'document', 'document_id' => 1]],
                    'sections' => [
                        [
                            'key' => 'section-1',
                            'title' => 'Раздел',
                            'work_items' => $workItems,
                        ],
                    ],
                ],
            ],
            'problem_flags' => [],
        ];
    }

    private function service(): EstimateValidationService
    {
        return new EstimateValidationService();
    }
}
