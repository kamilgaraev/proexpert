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
