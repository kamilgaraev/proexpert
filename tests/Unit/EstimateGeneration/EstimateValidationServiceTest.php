<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationNoAirWorkItemPolicy;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationReviewQueueQuery;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateValidationService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\ReviewSummarySnapshot;
use PHPUnit\Framework\TestCase;

final class EstimateValidationServiceTest extends TestCase
{
    public function test_validation_refreshes_the_canonical_review_projection_and_freshness_fence(): void
    {
        $draft = $this->service()->validate($this->draft([[
            'key' => 'work-1',
            'item_type' => 'priced_work',
            'name' => 'Черновое название',
            'pricing_status' => 'not_calculated',
            'pricing_blocker' => 'normative_required',
            'validation_flags' => ['safe_norm_required', 'pricing_not_calculated'],
            'normative_match' => ['status' => 'candidate'],
        ]]));
        $session = new EstimateGenerationSession(['draft_payload' => $draft]);
        $query = new EstimateGenerationReviewQueueQuery;

        self::assertTrue($query->hasFreshProjection($session));
        self::assertSame('Черновое название', $draft['quality_summary']['review_queue_items'][0]['work_item']['name']);
        $firstVersion = $draft['quality_summary']['review_items']['source_version'];

        $draft['local_estimates'][0]['sections'][0]['work_items'][0]['name'] = 'Актуальное название';
        self::assertNotSame($firstVersion, ReviewSummarySnapshot::contentVersion($draft));

        $refreshed = $this->service()->validate($draft);
        self::assertTrue($query->hasFreshProjection(new EstimateGenerationSession(['draft_payload' => $refreshed])));
        self::assertSame('Актуальное название', $refreshed['quality_summary']['review_queue_items'][0]['work_item']['name']);
        self::assertNotSame($firstVersion, $refreshed['quality_summary']['review_items']['source_version']);
    }

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

    public function test_generic_priced_work_is_forced_to_manual_review_even_with_positive_price(): void
    {
        $draft = $this->service()->validate($this->draft([
            [
                'key' => 'generic-complex-work',
                'item_type' => 'priced_work',
                'name' => 'Комплекс строительных работ',
                'normative_search_text' => 'Комплекс строительных работ',
                'unit' => 'компл',
                'quantity' => 1,
                'quantity_basis' => 'Документация, стр. 1',
                'total_cost' => 250000,
                'materials' => [['total_price' => 180000]],
                'labor' => [['total_price' => 50000]],
                'machinery' => [['total_price' => 20000]],
                'pricing_status' => 'calculated',
                'normative_rate_code' => '01-01-001-01',
                'normative_match' => [
                    'status' => 'matched',
                    'decision' => ['status' => 'accepted'],
                ],
                'validation_flags' => [],
                'confidence' => 0.93,
            ],
        ]));

        $item = $draft['local_estimates'][0]['sections'][0]['work_items'][0];

        self::assertSame('not_calculated', $item['pricing_status']);
        self::assertSame(EstimateGenerationNoAirWorkItemPolicy::BLOCKER, $item['pricing_blocker']);
        self::assertSame(0, $item['total_cost']);
        self::assertSame([], $item['materials']);
        self::assertContains(EstimateGenerationNoAirWorkItemPolicy::FLAG, $item['validation_flags']);
        self::assertContains(EstimateGenerationNoAirWorkItemPolicy::NO_AIR_FLAG, $item['validation_flags']);
        self::assertContains('pricing_not_calculated', $item['validation_flags']);
        self::assertSame(1, $draft['quality_summary']['safe_norm_required_work_items']);
        self::assertSame(1, $draft['quality_summary']['not_calculated_work_items']);
    }

    public function test_partial_norm_resource_warning_blocks_calculated_work(): void
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
                    'warnings' => ['norm_with_unpriced_resources'],
                    'decision' => [
                        'status' => 'accepted',
                        'warnings' => ['norm_with_unpriced_resources'],
                    ],
                ],
                'validation_flags' => [],
                'confidence' => 0.92,
            ],
        ]));

        $item = $draft['local_estimates'][0]['sections'][0]['work_items'][0];

        self::assertSame('not_calculated', $item['pricing_status']);
        self::assertSame('norm_with_unpriced_resources', $item['pricing_blocker']);
        self::assertContains('normative_price_required', $item['validation_flags']);
        self::assertContains('safe_norm_required', $item['validation_flags']);
        self::assertContains('pricing_not_calculated', $item['validation_flags']);
        self::assertContains('normative_price_required', $draft['problem_flags']);
        self::assertContains('normative_price_required', $draft['quality_summary']['critical_flags']);
        self::assertSame(1, $draft['quality_summary']['normative_price_required_work_items']);
        self::assertSame(1, $draft['quality_summary']['safe_norm_required_work_items']);
        self::assertSame(1, $draft['quality_summary']['not_calculated_work_items']);
    }

    public function test_project_document_norm_reference_without_code_requires_norm_selection(): void
    {
        $draft = $this->service()->validate($this->draft([
            [
                'key' => 'project-reference-without-code',
                'item_type' => 'priced_work',
                'name' => 'Монтаж трубопровода',
                'unit' => 'м',
                'quantity' => 12,
                'quantity_basis' => 'Проектная документация, лист ВК-1',
                'total_cost' => 12000,
                'materials' => [['total_price' => 8000]],
                'labor' => [['total_price' => 3000]],
                'machinery' => [['total_price' => 1000]],
                'pricing_status' => 'calculated',
                'normative_match' => [
                    'status' => 'matched',
                    'decision' => ['status' => 'accepted'],
                    'resources_count' => 3,
                    'priced_resources_count' => 3,
                ],
                'source_refs' => [[
                    'type' => 'project_document_norm_reference',
                    'filename' => 'spec.pdf',
                    'page_number' => 3,
                ]],
                'metadata' => [
                    'generation_source' => 'project_document_normative_reference',
                ],
                'validation_flags' => [],
                'confidence' => 0.9,
            ],
        ]));

        $item = $draft['local_estimates'][0]['sections'][0]['work_items'][0];

        self::assertSame('not_calculated', $item['pricing_status']);
        self::assertSame('normative_code_required', $item['pricing_blocker']);
        self::assertContains('normative_code_required', $item['validation_flags']);
        self::assertContains('safe_norm_required', $item['validation_flags']);
        self::assertContains('pricing_not_calculated', $item['validation_flags']);
        self::assertContains('normative_code_required', $draft['problem_flags']);
        self::assertContains('normative_code_required', $draft['quality_summary']['critical_flags']);
        self::assertSame(1, $draft['quality_summary']['normative_code_required_work_items']);
        self::assertSame(1, $draft['quality_summary']['normative_items']['code_required']);
        self::assertSame(1, $draft['quality_summary']['not_calculated_work_items']);
        self::assertSame(0, $draft['quality_summary']['priced_work_items']);
    }

    public function test_auto_review_priced_normative_match_still_requires_manual_review(): void
    {
        $draft = $this->service()->validate($this->draft([
            [
                'key' => 'work-1',
                'item_type' => 'priced_work',
                'name' => 'Монтаж трубопровода',
                'unit' => 'м',
                'quantity' => 12,
                'quantity_basis' => 'По спецификации, стр. 2',
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

    public function test_quantity_review_item_is_visible_but_not_counted_as_zero_price_work(): void
    {
        $draft = $this->service()->validate($this->draft([
            [
                'key' => 'quantity-review-1',
                'item_type' => 'quantity_review',
                'name' => 'Расчетная площадь стен по планировке',
                'unit' => 'м2',
                'quantity' => 220.5,
                'quantity_formula' => 'rough.walls',
                'quantity_basis' => 'Расчет по планировке требует подтверждения',
                'total_cost' => 0,
                'materials' => [],
                'labor' => [],
                'machinery' => [],
                'pricing_status' => 'not_applicable',
                'pricing_blocker' => 'quantity_review_required',
                'validation_flags' => ['quantity_review_required'],
                'confidence' => 0.68,
                'source_refs' => [['document_id' => 10, 'page_number' => 1]],
            ],
            [
                'key' => 'work-1',
                'item_type' => 'priced_work',
                'name' => 'Устройство основания пола',
                'unit' => 'м2',
                'quantity' => 87.14,
                'quantity_basis' => 'Площадь помещений по планировке',
                'total_cost' => 120000,
                'materials' => [['total_price' => 80000]],
                'labor' => [['total_price' => 30000]],
                'machinery' => [['total_price' => 10000]],
                'pricing_status' => 'calculated',
                'normative_match' => [
                    'status' => 'matched',
                    'decision' => ['status' => 'accepted'],
                ],
                'validation_flags' => [],
                'confidence' => 0.91,
            ],
        ]));

        self::assertSame(2, $draft['quality_summary']['total_work_items']);
        self::assertSame(1, $draft['quality_summary']['priced_work_items']);
        self::assertSame(1, $draft['quality_summary']['quantity_review_work_items']);
        self::assertSame(0, $draft['quality_summary']['zero_price_work_items']);
        self::assertSame(0, $draft['quality_summary']['not_calculated_work_items']);
        self::assertSame('review_required', $draft['quality_summary']['status']);
        self::assertContains('quantity_review_required', $draft['problem_flags']);
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

    public function test_duplicate_priced_work_items_from_different_sources_require_manual_review(): void
    {
        $workItem = [
            'item_type' => 'priced_work',
            'name' => 'Окраска стен',
            'normative_search_text' => 'окраска стен водно-дисперсионной краской',
            'normative_search_key' => '15|finishing|paint|м2',
            'unit' => 'м2',
            'quantity' => 180,
            'quantity_basis' => 'По проектной документации',
            'total_cost' => 95000,
            'materials' => [['total_price' => 60000]],
            'labor' => [['total_price' => 25000]],
            'machinery' => [['total_price' => 10000]],
            'pricing_status' => 'calculated',
            'normative_match' => [
                'status' => 'matched',
                'decision' => ['status' => 'accepted'],
            ],
            'validation_flags' => [],
            'confidence' => 0.88,
        ];

        $draft = $this->service()->validate($this->draft([
            [
                'key' => 'statement-paint',
                ...$workItem,
                'source_refs' => [['document_id' => 1, 'page_number' => 2, 'fact_id' => 11]],
            ],
            [
                'key' => 'planner-paint',
                ...$workItem,
                'source_refs' => [['document_id' => 2, 'page_number' => 1, 'takeoff_id' => 34]],
            ],
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
     * @param  array<int, array<string, mixed>>  $workItems
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
        return new EstimateValidationService;
    }
}
