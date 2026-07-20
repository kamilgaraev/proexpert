<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationNoAirWorkItemPolicy;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationPackagePersistenceService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateValidationService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeCandidateFeedbackService;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

final class NormativeCandidateFeedbackServiceTest extends TestCase
{
    public function test_rejects_current_normative_match_and_marks_work_item_not_calculated(): void
    {
        $draft = $this->draft([
            'key' => 'earth.backfill',
            'name' => 'Обратная засыпка пазух',
            'item_type' => 'priced_work',
            'unit' => 'м3',
            'quantity' => 12.5,
            'quantity_basis' => 'Ведомость объемов',
            'normative_rate_code' => '01-02-057-01',
            'pricing_status' => 'calculated',
            'price_source' => 'normative',
            'materials' => [['code' => '101', 'total_cost' => 100.0]],
            'labor' => [['code' => '1-1', 'total_cost' => 200.0]],
            'machinery' => [['code' => '400001', 'total_cost' => 300.0]],
            'other_resources' => [['name' => 'Прочее', 'total_cost' => 50.0]],
            'work_cost' => 200.0,
            'materials_cost' => 100.0,
            'machinery_cost' => 300.0,
            'labor_cost' => 200.0,
            'total_cost' => 600.0,
            'validation_flags' => [],
            'normative_match' => [
                'status' => 'matched',
                'norm_id' => 88,
                'code' => '01-02-057-01',
                'name' => 'Обратная засыпка',
                'decision' => [
                    'status' => 'accepted',
                    'can_use_for_pricing' => true,
                ],
            ],
            'normative_candidates' => [
                ['norm_id' => 88, 'code' => '01-02-057-01', 'name' => 'Обратная засыпка'],
                ['norm_id' => 89, 'code' => '01-02-058-01', 'name' => 'Планировка'],
            ],
        ]);

        $updated = $this->service()->applyRejectionToDraft($draft, 'earth.backfill', [
            'norm_id' => 88,
            'normative_code' => '01-02-057-01',
            'reason' => 'Норма не подходит',
        ], 'Нужно подобрать другую норму');
        $workItem = $updated['local_estimates'][0]['sections'][0]['work_items'][0];

        self::assertSame('rejected', $workItem['normative_match']['status']);
        self::assertSame('rejected', $workItem['normative_match']['decision']['status']);
        self::assertFalse($workItem['normative_match']['decision']['can_use_for_pricing']);
        self::assertContains('rejected_by_user', $workItem['normative_match']['warnings']);
        self::assertContains('safe_norm_required', $workItem['normative_match']['decision']['warnings']);
        self::assertNull($workItem['normative_rate_code']);
        self::assertSame([], $workItem['materials']);
        self::assertSame([], $workItem['labor']);
        self::assertSame([], $workItem['machinery']);
        self::assertSame([], $workItem['other_resources']);
        self::assertSame(0.0, $workItem['total_cost']);
        self::assertNull($workItem['price_source']);
        self::assertSame('not_calculated', $workItem['pricing_status']);
        self::assertSame('normative_rejected', $workItem['pricing_blocker']);
        self::assertContains('normative_rejected_by_user', $workItem['validation_flags']);
        self::assertContains('safe_norm_required', $workItem['validation_flags']);
        self::assertContains('pricing_not_calculated', $workItem['validation_flags']);
        self::assertSame('rejected', $workItem['normative_candidates'][0]['user_feedback']);
        self::assertTrue($workItem['normative_candidates'][0]['rejected_by_user']);
        self::assertSame('rejected_by_user', $workItem['metadata']['normative_feedback']['status']);
    }

    public function test_rejects_alternative_candidate_without_breaking_current_match(): void
    {
        $draft = $this->draft([
            'key' => 'heating.pipes',
            'name' => 'Прокладка труб отопления',
            'item_type' => 'priced_work',
            'unit' => 'м',
            'quantity' => 40.0,
            'quantity_basis' => 'Спецификация',
            'normative_rate_code' => '16-02-052-05',
            'pricing_status' => 'calculated',
            'price_source' => 'normative',
            'materials' => [['code' => '103', 'total_cost' => 1000.0]],
            'labor' => [],
            'machinery' => [],
            'other_resources' => [],
            'total_cost' => 1000.0,
            'validation_flags' => [],
            'normative_match' => [
                'status' => 'matched',
                'norm_id' => 5,
                'code' => '16-02-052-05',
                'decision' => [
                    'status' => 'accepted',
                    'can_use_for_pricing' => true,
                ],
            ],
            'normative_candidates' => [
                ['norm_id' => 5, 'code' => '16-02-052-05', 'name' => 'Прокладка труб'],
                ['norm_id' => 6, 'code' => '16-02-052-06', 'name' => 'Монтаж радиаторов'],
            ],
        ]);

        $updated = $this->service()->applyRejectionToDraft($draft, 'heating.pipes', [
            'norm_id' => 6,
            'normative_code' => '16-02-052-06',
            'reason' => 'Это не прокладка труб',
        ]);
        $workItem = $updated['local_estimates'][0]['sections'][0]['work_items'][0];

        self::assertSame('matched', $workItem['normative_match']['status']);
        self::assertSame('accepted', $workItem['normative_match']['decision']['status']);
        self::assertSame('16-02-052-05', $workItem['normative_rate_code']);
        self::assertSame('calculated', $workItem['pricing_status']);
        self::assertSame(1000.0, $workItem['total_cost']);
        self::assertArrayNotHasKey('user_feedback', $workItem['normative_candidates'][0]);
        self::assertSame('rejected', $workItem['normative_candidates'][1]['user_feedback']);
        self::assertTrue($workItem['normative_candidates'][1]['rejected_by_user']);
        self::assertContains('rejected_by_user', $workItem['normative_candidates'][1]['warnings']);
    }

    public function test_rejects_feedback_without_norm_identity(): void
    {
        $this->expectException(ValidationException::class);

        $this->service()->applyRejectionToDraft($this->draft([
            'key' => 'earth.backfill',
            'name' => 'Обратная засыпка пазух',
            'item_type' => 'priced_work',
            'normative_match' => ['status' => 'not_found'],
            'normative_candidates' => [],
        ]), 'earth.backfill', []);
    }

    public function test_rejects_norm_that_is_not_current_match_or_offered_candidate(): void
    {
        $this->expectException(ValidationException::class);

        $this->service()->applyRejectionToDraft($this->draft([
            'key' => 'earth.backfill',
            'name' => 'Обратная засыпка пазух',
            'item_type' => 'priced_work',
            'normative_match' => [
                'status' => 'matched',
                'norm_id' => 88,
                'code' => '01-02-057-01',
            ],
            'normative_candidates' => [
                ['norm_id' => 89, 'code' => '01-02-058-01'],
            ],
        ]), 'earth.backfill', [
            'norm_id' => 777,
            'normative_code' => '99-99-999-99',
        ]);
    }

    public function test_confirms_drawing_quantity_and_moves_work_item_to_norm_selection(): void
    {
        $draft = $this->draft([
            'key' => 'rough.walls',
            'name' => 'Штукатурка стен',
            'item_type' => 'quantity_review',
            'unit' => 'м2',
            'quantity' => 220.5,
            'quantity_basis' => 'Площадь стен извлечена из планировки.',
            'pricing_status' => 'not_applicable',
            'pricing_blocker' => 'quantity_review_required',
            'materials' => [],
            'labor' => [],
            'machinery' => [],
            'other_resources' => [],
            'total_cost' => 0.0,
            'validation_flags' => ['quantity_review_required'],
            'metadata' => [
                'quantity_key' => 'rough.walls',
                'display_role' => 'quantity_review',
            ],
        ]);

        $updated = $this->service()->applyQuantityConfirmationToDraft($draft, 'rough.walls', [
            'quantity' => 218.25,
            'unit' => 'м2',
            'quantity_basis' => 'Проверено по планировке, площадь стен 218,25 м2.',
        ], 'Проверил площадь стен');
        $workItem = $updated['local_estimates'][0]['sections'][0]['work_items'][0];

        self::assertSame('priced_work', $workItem['item_type']);
        self::assertSame(218.25, $workItem['quantity']);
        self::assertSame('м2', $workItem['unit']);
        self::assertSame('Проверено по планировке, площадь стен 218,25 м2.', $workItem['quantity_basis']);
        self::assertSame('not_calculated', $workItem['pricing_status']);
        self::assertSame('normative_required', $workItem['pricing_blocker']);
        self::assertSame(0.0, $workItem['total_cost']);
        self::assertContains('normative_required', $workItem['validation_flags']);
        self::assertContains('safe_norm_required', $workItem['validation_flags']);
        self::assertContains('pricing_not_calculated', $workItem['validation_flags']);
        self::assertNotContains('quantity_review_required', $workItem['validation_flags']);
        self::assertSame('confirmed_by_user', $workItem['metadata']['quantity_feedback']['status']);
        self::assertSame(218.25, $workItem['metadata']['quantity_feedback']['quantity']);
    }

    public function test_confirms_current_review_priced_normative_match(): void
    {
        $draft = $this->draft([
            'key' => 'roof.insulation',
            'name' => 'Roof insulation',
            'item_type' => 'priced_work',
            'unit' => 'm2',
            'quantity' => 100.0,
            'quantity_basis' => 'Drawing A101',
            'normative_rate_code' => '12-01-013-01',
            'pricing_status' => 'calculated_review_required',
            'price_source' => 'fsnb_normative',
            'materials' => [['total_price' => 10000.0]],
            'labor' => [],
            'machinery' => [],
            'other_resources' => [],
            'work_cost' => 0.0,
            'materials_cost' => 10000.0,
            'machinery_cost' => 0.0,
            'labor_cost' => 0.0,
            'total_cost' => 10000.0,
            'validation_flags' => [
                'requires_normative_review',
                'safe_normative_analog',
                'normative_match_low_confidence',
            ],
            'normative_match' => [
                'status' => 'matched',
                'selected_by_user' => false,
                'norm_id' => 77,
                'code' => '12-01-013-01',
                'warnings' => [
                    'requires_normative_review',
                    'safe_normative_analog',
                    'low_confidence',
                ],
                'decision' => [
                    'status' => 'review_priced',
                    'can_use_for_pricing' => true,
                    'warnings' => [
                        'requires_normative_review',
                        'safe_normative_analog',
                        'low_confidence',
                    ],
                ],
            ],
        ]);

        $updated = $this->service()->applyNormativeConfirmationToDraft($draft, 'roof.insulation', [
            'norm_id' => 77,
            'normative_code' => '12-01-013-01',
        ], 'Checked manually.');
        $workItem = $updated['local_estimates'][0]['sections'][0]['work_items'][0];

        self::assertSame('calculated', $workItem['pricing_status']);
        self::assertSame('matched', $workItem['normative_match']['status']);
        self::assertSame('accepted', $workItem['normative_match']['decision']['status']);
        self::assertTrue($workItem['normative_match']['selected_by_user']);
        self::assertTrue($workItem['normative_match']['decision']['user_confirmed']);
        self::assertNotContains('requires_normative_review', $workItem['validation_flags']);
        self::assertNotContains('safe_normative_analog', $workItem['validation_flags']);
        self::assertNotContains('normative_match_low_confidence', $workItem['validation_flags']);
        self::assertSame('confirmed_by_user', $workItem['metadata']['normative_confirmation']['status']);
    }

    public function test_removes_duplicate_work_item_from_draft(): void
    {
        $duplicateWorkItem = [
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
            'source_refs' => [['document_id' => 1, 'page_number' => 1]],
            'validation_flags' => ['possible_duplicate_work_item', 'requires_duplicate_review'],
            'confidence' => 0.92,
        ];
        $draft = $this->drafts([
            ['key' => 'work-1', ...$duplicateWorkItem],
            ['key' => 'work-2', ...$duplicateWorkItem],
        ]);

        $updated = $this->service()->applyDuplicateResolutionToDraft($draft, 'work-2', [
            'action' => 'remove_item',
        ], 'Оставляем первую позицию, повтор удаляем.');

        $workItems = $updated['local_estimates'][0]['sections'][0]['work_items'];

        self::assertSame(['work-1'], array_column($workItems, 'key'));
        self::assertNotContains('possible_duplicate_work_item', $workItems[0]['validation_flags']);
        self::assertNotContains('requires_duplicate_review', $workItems[0]['validation_flags']);
        self::assertSame('duplicate_resolution', $updated['review_decisions'][0]['type']);
        self::assertSame('remove_item', $updated['review_decisions'][0]['action']);
        self::assertSame(['work-2'], $updated['review_decisions'][0]['removed_work_item_keys']);
    }

    public function test_duplicate_resolution_preserves_other_duplicate_groups(): void
    {
        $concreteWorkItem = [
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
            'validation_flags' => ['possible_duplicate_work_item', 'requires_duplicate_review'],
            'confidence' => 0.92,
        ];
        $paintWorkItem = [
            ...$concreteWorkItem,
            'name' => 'Wall painting',
            'normative_search_text' => 'wall painting',
            'normative_search_key' => 'finishing|paint|m2',
            'unit' => 'm2',
            'quantity' => 180,
        ];
        $draft = $this->drafts([
            ['key' => 'concrete-1', ...$concreteWorkItem],
            ['key' => 'concrete-2', ...$concreteWorkItem],
            ['key' => 'paint-1', ...$paintWorkItem],
            ['key' => 'paint-2', ...$paintWorkItem],
        ]);

        $updated = $this->service()->applyDuplicateResolutionToDraft($draft, 'concrete-2', [
            'action' => 'remove_item',
        ]);
        $itemsByKey = [];
        foreach ($updated['local_estimates'][0]['sections'][0]['work_items'] as $workItem) {
            $itemsByKey[$workItem['key']] = $workItem;
        }

        self::assertArrayNotHasKey('concrete-2', $itemsByKey);
        self::assertNotContains('requires_duplicate_review', $itemsByKey['concrete-1']['validation_flags']);
        self::assertContains('requires_duplicate_review', $itemsByKey['paint-1']['validation_flags']);
        self::assertContains('requires_duplicate_review', $itemsByKey['paint-2']['validation_flags']);
    }

    public function test_merges_duplicate_work_item_into_existing_target_and_preserves_evidence(): void
    {
        $duplicateWorkItem = [
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
            'source_refs' => [['type' => 'document', 'filename' => 'A101.pdf', 'page_number' => 1]],
            'validation_flags' => ['possible_duplicate_work_item', 'requires_duplicate_review'],
            'confidence' => 0.92,
        ];
        $draft = $this->drafts([
            ['key' => 'work-1', ...$duplicateWorkItem],
            [
                'key' => 'work-2',
                ...$duplicateWorkItem,
                'quantity_basis' => 'Drawing A102, page 2',
                'source_refs' => [['type' => 'document', 'filename' => 'A102.pdf', 'page_number' => 2]],
            ],
        ]);

        $updated = $this->service()->applyDuplicateResolutionToDraft($draft, 'work-2', [
            'action' => 'merge_with_existing',
            'target_work_item_key' => 'work-1',
        ], 'Merged duplicate evidence.');

        $workItems = $updated['local_estimates'][0]['sections'][0]['work_items'];
        $target = $workItems[0];

        self::assertSame(['work-1'], array_column($workItems, 'key'));
        self::assertCount(2, $target['source_refs']);
        self::assertSame(['A101.pdf', 'A102.pdf'], array_column($target['source_refs'], 'filename'));
        self::assertStringContainsString('Drawing A101, page 1', $target['quantity_basis']);
        self::assertStringContainsString('Drawing A102, page 2', $target['quantity_basis']);
        self::assertSame('merged_by_user', $target['metadata']['duplicate_resolution']['status']);
        self::assertSame(['work-2'], $target['metadata']['duplicate_resolution']['removed_work_item_keys']);
        self::assertSame('merge_with_existing', $updated['review_decisions'][0]['action']);
        self::assertSame('work-1', $updated['review_decisions'][0]['kept_work_item_key']);
        self::assertSame(['work-2'], $updated['review_decisions'][0]['removed_work_item_keys']);
    }

    public function test_duplicate_merge_preserves_a_distinct_technological_layer_with_the_same_norm_and_quantity(): void
    {
        $sharedWorkItem = [
            'item_type' => 'priced_work',
            'name' => 'Изоляционный слой кровли',
            'normative_search_text' => 'устройство прокладочной изоляции в один слой',
            'normative_rate_code' => '12-01-015-03',
            'unit' => 'm2',
            'quantity' => 152.955,
            'quantity_basis' => 'Геометрия кровли',
            'total_cost' => 15000,
            'materials' => [['total_price' => 10000]],
            'labor' => [['total_price' => 4500]],
            'machinery' => [['total_price' => 500]],
            'pricing_status' => 'calculated',
            'validation_flags' => [],
            'confidence' => 0.88,
        ];
        $vaporBarrier = [
            ...$sharedWorkItem,
            'quantity_formula' => 'roof.area',
            'metadata' => [
                'quantity_key' => 'roof.area',
                'composition_work_key' => 'roof.vapor_barrier',
                'material_scenario_work_key' => 'roof.vapor_barrier',
            ],
            'validation_flags' => ['possible_duplicate_work_item', 'requires_duplicate_review'],
        ];
        $draft = $this->drafts([
            ['key' => 'vapor-1', ...$vaporBarrier],
            ['key' => 'vapor-2', ...$vaporBarrier],
            [
                'key' => 'membrane-1',
                ...$sharedWorkItem,
                'quantity_formula' => 'roof.area',
                'metadata' => [
                    'quantity_key' => 'roof.area',
                    'composition_work_key' => 'roof.membrane',
                    'material_scenario_work_key' => 'roof.membrane',
                ],
            ],
        ]);

        $updated = $this->service()->applyDuplicateResolutionToDraft($draft, 'vapor-2', [
            'action' => 'merge_with_existing',
            'target_work_item_key' => 'vapor-1',
        ]);

        self::assertSame(
            ['vapor-1', 'membrane-1'],
            array_column($updated['local_estimates'][0]['sections'][0]['work_items'], 'key'),
        );
        self::assertSame(['vapor-2'], $updated['review_decisions'][0]['removed_work_item_keys']);
    }

    public function test_duplicate_merge_accepts_the_same_work_from_sources_with_different_metadata_completeness(): void
    {
        $sharedWorkItem = [
            'item_type' => 'priced_work',
            'name' => 'Пароизоляция скатной кровли',
            'normative_search_text' => 'устройство пароизоляции кровли прокладочной в один слой',
            'normative_rate_code' => '12-01-015-03',
            'unit' => 'm2',
            'quantity' => 152.955,
            'quantity_formula' => 'roof.vapor_barrier',
            'quantity_basis' => 'Геометрия кровли',
            'total_cost' => 15000,
            'materials' => [['total_price' => 10000]],
            'labor' => [['total_price' => 4500]],
            'machinery' => [['total_price' => 500]],
            'pricing_status' => 'calculated',
            'validation_flags' => ['possible_duplicate_work_item', 'requires_duplicate_review'],
            'confidence' => 0.88,
        ];
        $draft = $this->drafts([
            [
                'key' => 'document-vapor-barrier',
                ...$sharedWorkItem,
                'metadata' => ['quantity_key' => 'roof.vapor_barrier'],
            ],
            [
                'key' => 'planner-vapor-barrier',
                ...$sharedWorkItem,
                'metadata' => [
                    'quantity_key' => 'roof.vapor_barrier',
                    'composition_work_key' => 'roof.vapor_barrier',
                    'material_scenario_work_key' => 'roof.vapor_barrier',
                ],
            ],
        ]);

        $updated = $this->service()->applyDuplicateResolutionToDraft($draft, 'planner-vapor-barrier', [
            'action' => 'merge_with_existing',
            'target_work_item_key' => 'document-vapor-barrier',
        ]);

        self::assertSame(
            ['document-vapor-barrier'],
            array_column($updated['local_estimates'][0]['sections'][0]['work_items'], 'key'),
        );
        self::assertSame(
            ['planner-vapor-barrier'],
            $updated['review_decisions'][0]['removed_work_item_keys'],
        );
    }

    public function test_removes_generic_no_air_work_item_from_draft(): void
    {
        $draft = $this->drafts([
            [
                'key' => 'generic-front',
                'item_type' => 'priced_work',
                'name' => 'Generic front preparation',
                'unit' => 'compl',
                'quantity' => 1,
                'quantity_basis' => 'Planner generic composition row',
                'pricing_status' => 'not_calculated',
                'pricing_blocker' => EstimateGenerationNoAirWorkItemPolicy::BLOCKER,
                'total_cost' => 0.0,
                'materials' => [],
                'labor' => [],
                'machinery' => [],
                'other_resources' => [],
                'validation_flags' => [
                    EstimateGenerationNoAirWorkItemPolicy::FLAG,
                    EstimateGenerationNoAirWorkItemPolicy::NO_AIR_FLAG,
                    'pricing_not_calculated',
                    'safe_norm_required',
                ],
            ],
            [
                'key' => 'real-work',
                'item_type' => 'priced_work',
                'name' => 'Concrete work',
                'unit' => 'm3',
                'quantity' => 8,
                'quantity_basis' => 'Drawing A101',
                'pricing_status' => 'calculated',
                'normative_rate_code' => '06-01-001-01',
                'total_cost' => 120000,
                'materials' => [['total_price' => 80000]],
                'labor' => [['total_price' => 25000]],
                'machinery' => [['total_price' => 15000]],
                'other_resources' => [],
                'validation_flags' => [],
            ],
        ]);

        $updated = $this->service()->applyWorkItemResolutionToDraft($draft, 'generic-front', [
            'action' => 'remove_item',
        ], 'Generic composition row removed by reviewer.');

        $workItems = $updated['local_estimates'][0]['sections'][0]['work_items'];

        self::assertSame(['real-work'], array_column($workItems, 'key'));
        self::assertSame('work_item_resolution', $updated['review_decisions'][0]['type']);
        self::assertSame('remove_item', $updated['review_decisions'][0]['action']);
        self::assertSame(['generic-front'], $updated['review_decisions'][0]['removed_work_item_keys']);
    }

    /**
     * @param  array<string, mixed>  $workItem
     * @return array<string, mixed>
     */
    private function draft(array $workItem): array
    {
        return $this->drafts([$workItem]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $workItems
     * @return array<string, mixed>
     */
    private function drafts(array $workItems): array
    {
        return [
            'local_estimates' => [[
                'key' => 'local-1',
                'title' => 'Локальная смета',
                'scope_type' => 'earthworks',
                'source_refs' => [['type' => 'document']],
                'sections' => [[
                    'key' => 'section-1',
                    'title' => 'Земляные работы',
                    'work_items' => $workItems,
                ]],
            ]],
            'problem_flags' => [],
        ];
    }

    private function service(): NormativeCandidateFeedbackService
    {
        return new NormativeCandidateFeedbackService(
            $this->createMock(EstimateValidationService::class),
            $this->createMock(EstimateGenerationPackagePersistenceService::class),
            static fn (string $key): string => $key,
            static fn (array $messages): ValidationException => new class($messages) extends ValidationException
            {
                /**
                 * @param  array<string, array<int, string>>  $messages
                 */
                public function __construct(private readonly array $messages) {}

                public function errors()
                {
                    return $this->messages;
                }
            },
        );
    }
}
