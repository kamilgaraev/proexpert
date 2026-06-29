<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackage;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackageItem;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationPackagePresenter;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationNoAirWorkItemPolicy;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationReviewItemService;
use PHPUnit\Framework\TestCase;

final class EstimateGenerationReviewItemServiceTest extends TestCase
{
    public function test_collects_action_ready_review_items_and_skips_service_rows(): void
    {
        $result = $this->service()->forSession(new EstimateGenerationSession([
            'draft_payload' => $this->draft([
                $this->workItem([
                    'key' => 'operation-row',
                    'item_type' => 'operation',
                    'pricing_status' => 'not_calculated',
                    'pricing_blocker' => 'normative_required',
                    'validation_flags' => ['pricing_not_calculated'],
                ]),
                $this->workItem([
                    'key' => 'quantity-review',
                    'item_type' => 'quantity_review',
                    'pricing_status' => 'not_applicable',
                    'pricing_blocker' => 'quantity_review_required',
                    'validation_flags' => ['quantity_review_required'],
                ]),
                $this->workItem([
                    'key' => 'duplicate-work',
                    'validation_flags' => ['requires_duplicate_review'],
                    'pricing_status' => 'calculated',
                    'total_cost' => 1000,
                    'normative_match' => [
                        'norm_id' => 101,
                        'status' => 'matched',
                        'decision' => ['status' => 'accepted'],
                    ],
                ]),
                $this->workItem([
                    'key' => 'select-norm',
                    'pricing_status' => 'not_calculated',
                    'pricing_blocker' => 'normative_required',
                    'validation_flags' => ['safe_norm_required', 'pricing_not_calculated'],
                    'normative_match' => ['status' => 'candidate'],
                    'normative_candidates' => [
                        ['norm_id' => 201, 'code' => '01-01-001-01'],
                    ],
                ]),
                $this->workItem([
                    'key' => 'check-price',
                    'pricing_status' => 'not_calculated',
                    'pricing_blocker' => 'norm_with_unpriced_resources',
                    'validation_flags' => ['pricing_not_calculated'],
                    'normative_match' => [
                        'norm_id' => 301,
                        'code' => '16-02-052-05',
                        'status' => 'matched',
                        'decision' => ['status' => 'accepted'],
                    ],
                ]),
                $this->workItem([
                    'key' => 'optional-alternative',
                    'pricing_status' => 'calculated',
                    'total_cost' => 2000,
                    'normative_match' => [
                        'norm_id' => 401,
                        'code' => '10-01-001-01',
                        'status' => 'matched',
                        'decision' => ['status' => 'accepted'],
                    ],
                    'normative_candidates' => [
                        ['norm_id' => 401, 'code' => '10-01-001-01'],
                        ['norm_id' => 402, 'code' => '10-01-001-02'],
                    ],
                ]),
            ]),
        ]));

        self::assertSame(5, $result['summary']['total']);
        self::assertSame(4, $result['summary']['blocking']);
        self::assertSame(1, $result['summary']['optional']);
        self::assertSame(1, $result['summary']['confirm_quantity']);
        self::assertSame(1, $result['summary']['resolve_duplicate']);
        self::assertSame(2, $result['summary']['select_norm']);
        self::assertSame(0, $result['summary']['check_price']);
        self::assertSame(1, $result['summary']['review_norm']);
        self::assertSame(
            ['quantity-review', 'duplicate-work', 'select-norm', 'check-price', 'optional-alternative'],
            array_column($result['items'], 'work_item_key')
        );

        $itemsByKey = [];
        foreach ($result['items'] as $item) {
            $itemsByKey[$item['work_item_key']] = $item;
        }

        self::assertSame('confirm_quantity', $itemsByKey['quantity-review']['required_action']);
        self::assertContains('quantity_review_required', $itemsByKey['quantity-review']['reason_codes']);
        self::assertSame('resolve_duplicate', $itemsByKey['duplicate-work']['required_action']);
        self::assertContains('requires_duplicate_review', $itemsByKey['duplicate-work']['reason_codes']);
        self::assertSame('select_norm', $itemsByKey['select-norm']['required_action']);
        self::assertSame(1, $itemsByKey['select-norm']['candidates_count']);
        self::assertSame('select_norm', $itemsByKey['check-price']['required_action']);
        self::assertTrue($itemsByKey['check-price']['has_current_norm']);
        self::assertSame('review_norm', $itemsByKey['optional-alternative']['required_action']);
        self::assertContains('normative_alternative_available', $itemsByKey['optional-alternative']['reason_codes']);
    }

    public function test_returns_empty_summary_for_missing_draft(): void
    {
        $result = $this->service()->forSession(new EstimateGenerationSession());

        self::assertSame([], $result['items']);
        self::assertSame(0, $result['summary']['total']);
        self::assertSame(0, $result['summary']['blocking']);
        self::assertSame(0, $result['summary']['warning']);
        self::assertSame(0, $result['summary']['optional']);
    }

    public function test_generic_calculated_work_item_is_blocking_review_item(): void
    {
        $result = $this->service()->forSession(new EstimateGenerationSession([
            'draft_payload' => $this->draft([
                $this->workItem([
                    'key' => 'generic-complex-work',
                    'name' => 'Комплекс строительных работ',
                    'normative_search_text' => 'Комплекс строительных работ',
                    'unit' => 'компл',
                    'quantity' => 1,
                    'total_cost' => 250000,
                    'pricing_status' => 'calculated',
                    'normative_rate_code' => '01-01-001-01',
                    'normative_match' => [
                        'norm_id' => 101,
                        'code' => '01-01-001-01',
                        'status' => 'matched',
                        'decision' => ['status' => 'accepted'],
                    ],
                    'materials' => [['total_price' => 180000]],
                    'labor' => [['total_price' => 50000]],
                    'machinery' => [['total_price' => 20000]],
                ]),
            ]),
        ]));

        self::assertSame(1, $result['summary']['total']);
        self::assertSame(1, $result['summary']['blocking']);
        self::assertSame('generic-complex-work', $result['items'][0]['work_item_key']);
        self::assertSame('resolve_generic_work', $result['items'][0]['required_action']);
        self::assertSame(1, $result['summary']['resolve_generic_work']);
        self::assertSame(EstimateGenerationNoAirWorkItemPolicy::BLOCKER, $result['items'][0]['pricing_blocker']);
        self::assertContains(EstimateGenerationNoAirWorkItemPolicy::FLAG, $result['items'][0]['reason_codes']);
        self::assertContains(EstimateGenerationNoAirWorkItemPolicy::NO_AIR_FLAG, $result['items'][0]['work_item']['validation_flags']);
    }

    public function test_uses_package_items_as_fallback_without_duplicating_draft_items(): void
    {
        $package = new EstimateGenerationPackage([
            'key' => 'local-1',
            'title' => 'Package estimate',
            'scope_type' => 'site',
            'source_refs' => [
                ['type' => 'document', 'filename' => 'package.pdf', 'page_number' => 2],
            ],
        ]);
        $package->setRelation('items', collect([
            new EstimateGenerationPackageItem([
                'key' => 'select-norm',
                'item_type' => 'priced_work',
                'name' => 'Duplicated package row',
                'unit' => 'm',
                'quantity' => 1,
                'total_cost' => 0,
                'flags' => ['pricing_not_calculated'],
                'metadata' => [
                    'pricing_status' => 'not_calculated',
                    'pricing_blocker' => 'normative_required',
                    'normative_match' => ['status' => 'not_found'],
                    'source_refs' => [
                        ['type' => 'document', 'filename' => 'package.pdf', 'page_number' => 2],
                    ],
                ],
            ]),
            new EstimateGenerationPackageItem([
                'key' => 'package-only',
                'item_type' => 'priced_work',
                'name' => 'Package only row',
                'unit' => 'm2',
                'quantity' => 12,
                'total_cost' => 0,
                'flags' => ['safe_norm_required', 'pricing_not_calculated'],
                'metadata' => [
                    'pricing_status' => 'not_calculated',
                    'pricing_blocker' => 'normative_required',
                    'normative_match' => ['status' => 'candidate'],
                    'normative_candidates' => [
                        ['norm_id' => 501, 'code' => '11-01-001-01'],
                    ],
                    'source_refs' => [
                        ['type' => 'document', 'filename' => 'package.pdf', 'page_number' => 2],
                    ],
                ],
            ]),
            new EstimateGenerationPackageItem([
                'key' => 'service-row',
                'item_type' => 'operation',
                'name' => 'Service row',
                'total_cost' => 0,
                'flags' => ['pricing_not_calculated'],
                'metadata' => ['pricing_status' => 'not_calculated'],
            ]),
        ]));

        $session = new EstimateGenerationSession([
            'draft_payload' => $this->draft([
                $this->workItem([
                    'key' => 'select-norm',
                    'name' => 'Draft row',
                    'pricing_status' => 'not_calculated',
                    'pricing_blocker' => 'normative_required',
                    'validation_flags' => ['safe_norm_required', 'pricing_not_calculated'],
                    'normative_match' => ['status' => 'candidate'],
                ]),
            ]),
        ]);
        $session->setRelation('packages', collect([$package]));

        $result = $this->service()->forSession($session);

        self::assertSame(2, $result['summary']['total']);
        self::assertSame(['select-norm', 'package-only'], array_column($result['items'], 'work_item_key'));

        $itemsByKey = [];
        foreach ($result['items'] as $item) {
            $itemsByKey[$item['work_item_key']] = $item;
        }

        self::assertSame('Draft row', $itemsByKey['select-norm']['work_item']['name']);
        self::assertSame('Package estimate', $itemsByKey['package-only']['local_estimate_title']);
        self::assertSame(1, $itemsByKey['package-only']['candidates_count']);
        self::assertSame('select_norm', $itemsByKey['package-only']['required_action']);
    }

    private function service(): EstimateGenerationReviewItemService
    {
        return new EstimateGenerationReviewItemService(new EstimateGenerationPackagePresenter());
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
                    'title' => 'Local estimate',
                    'sections' => [
                        [
                            'key' => 'section-1',
                            'title' => 'Section',
                            'work_items' => $workItems,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function workItem(array $overrides): array
    {
        return array_replace([
            'key' => 'work',
            'item_type' => 'priced_work',
            'name' => 'Work item',
            'work_category' => 'common',
            'description' => '',
            'unit' => 'm',
            'quantity' => 1,
            'quantity_formula' => '',
            'quantity_basis' => 'Document',
            'work_cost' => 0,
            'materials_cost' => 0,
            'machinery_cost' => 0,
            'labor_cost' => 0,
            'total_cost' => 0,
            'pricing_status' => 'not_calculated',
            'pricing_blocker' => null,
            'normative_match' => null,
            'normative_candidates' => [],
            'materials' => [],
            'labor' => [],
            'machinery' => [],
            'source_refs' => [
                [
                    'type' => 'document',
                    'filename' => 'plan.pdf',
                    'page_number' => 1,
                ],
            ],
            'confidence' => 0.8,
            'validation_flags' => [],
        ], $overrides);
    }
}
