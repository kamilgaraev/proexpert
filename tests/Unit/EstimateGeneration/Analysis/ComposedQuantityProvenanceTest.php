<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Analysis;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\ComposedQuantityProvenance;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateCompositionProjector;
use PHPUnit\Framework\TestCase;

final class ComposedQuantityProvenanceTest extends TestCase
{
    public function test_trusted_formula_can_include_a_related_opening_operand_and_context_is_separate(): void
    {
        $facts = [['id' => 'wall:length', 'entity_id' => 'wall:1', 'value' => '8', 'unit' => 'm'],
            ['id' => 'opening:area', 'entity_id' => 'opening:1', 'value' => '2', 'unit' => 'm2'],
            ['id' => 'description', 'entity_id' => 'project:1', 'value' => 'Покрасить стены']];
        $quantity = ['id' => 'wall:net', 'entity_id' => 'wall:1', 'value' => '22', 'unit' => 'm2',
            'operand_fact_ids' => ['wall:length', 'opening:area'], 'formula_key' => 'wall_net_area', 'source' => 'evidenced'];
        $result = (new ComposedQuantityProvenance([$quantity], $facts))->binding(['work_key' => 'finish.paint', 'entity_id' => 'wall:1', 'unit' => '100 m2',
            'derived_quantity_id' => 'wall:net', 'source_fact_ids' => ['wall:length', 'description']]);
        self::assertSame('wall:1', $result['entity_id']);
        self::assertSame(['wall:length', 'opening:area'], $result['operand_fact_ids']);
        self::assertSame($quantity, $result['quantity']);
        self::assertTrue($result['eligible']);
    }

    public function test_a_numeric_context_fact_without_a_formula_does_not_become_a_work_volume(): void
    {
        self::assertNull((new ComposedQuantityProvenance([], [['id' => 'floor:count', 'entity_id' => 'floor:1', 'value' => 2,
            'unit' => 'count', 'status' => 'confirmed']]))->binding(['derived_quantity_id' => null, 'source_fact_ids' => ['floor:count']]));
    }

    public function test_real_projector_retains_unknown_quantity_and_marks_the_reference_as_context(): void
    {
        $facts = [['id' => 'room:area', 'entity_id' => 'room:1', 'value' => 80, 'unit' => 'm2', 'status' => 'confirmed']];
        $intent = ['work_key' => 'paint-walls', 'name' => 'Окраска стен', 'entity_id' => 'room:1',
            'unit' => 'm2', 'derived_quantity_id' => null, 'source_fact_ids' => ['room:area'], 'technology_package_candidate' => null];
        $method = new \ReflectionMethod(EstimateCompositionProjector::class, 'supplementaryItems');
        $items = $method->invoke(new EstimateCompositionProjector, ['supplementary:paint' => $intent], [], $facts);
        self::assertNull($items[0]['quantity']);
        self::assertNull($items[0]['quantity_formula']);
        self::assertSame('m2', $items[0]['unit']);
        self::assertSame([['fact_id' => 'room:area', 'role' => 'context']], $items[0]['source_refs']);
        self::assertSame('room:1', $items[0]['metadata']['scope_entity_id']);
    }

    public function test_real_projector_records_all_actual_operands_separately_from_a_description(): void
    {
        $facts = [['id' => 'length', 'entity_id' => 'wall:1', 'value' => '8', 'unit' => 'm'],
            ['id' => 'height', 'entity_id' => 'wall:1', 'value' => '3', 'unit' => 'm'],
            ['id' => 'description', 'entity_id' => 'wall:1', 'value' => 'Окраска стен']];
        $quantity = ['id' => 'wall:area', 'entity_id' => 'wall:1', 'value' => '24', 'unit' => 'm2', 'operand_fact_ids' => ['length', 'height'],
            'formula_key' => 'wall_net_area', 'source' => 'evidenced'];
        $intent = ['work_key' => 'finish.paint', 'name' => 'Окраска стен', 'entity_id' => 'wall:1', 'unit' => 'm2',
            'derived_quantity_id' => 'wall:area', 'source_fact_ids' => ['description'], 'technology_package_candidate' => null];
        $method = new \ReflectionMethod(EstimateCompositionProjector::class, 'supplementaryItems');
        $items = $method->invoke(new EstimateCompositionProjector, ['supplementary:paint' => $intent], [$quantity], $facts);
        self::assertSame('24', $items[0]['quantity']);
        self::assertSame('wall:1', $items[0]['metadata']['scope_entity_id']);
        self::assertSame(['length', 'height'], $items[0]['metadata']['quantity_operand_fact_ids']);
        self::assertSame([['fact_id' => 'description', 'role' => 'context'], ['fact_id' => 'length', 'role' => 'quantity_operand'],
            ['fact_id' => 'height', 'role' => 'quantity_operand']], $items[0]['source_refs']);
    }

    public function test_floor_area_cannot_certify_wall_painting_and_unfamiliar_or_estimated_sources_remain_unknown(): void
    {
        $facts = [['id' => 'room:area', 'entity_id' => 'room:1', 'value' => '80', 'unit' => 'm2']];
        $quantity = ['id' => 'quantity:area', 'entity_id' => 'room:1', 'value' => '80', 'unit' => 'm2',
            'formula_key' => 'direct_floor_area', 'source' => 'evidenced', 'operand_fact_ids' => ['room:area']];
        $intent = ['work_key' => 'finish.paint', 'entity_id' => 'room:1', 'unit' => 'm2', 'derived_quantity_id' => 'quantity:area',
            'source_fact_ids' => ['room:area']];
        self::assertFalse((new ComposedQuantityProvenance([$quantity], $facts))->binding($intent)['eligible']);
        self::assertTrue((new ComposedQuantityProvenance([$quantity], $facts))->binding([...$intent, 'work_key' => 'finish.floor'])['eligible']);
        self::assertFalse((new ComposedQuantityProvenance([$quantity], $facts))->binding([...$intent, 'work_key' => 'unfamiliar.work'])['eligible']);
        self::assertFalse((new ComposedQuantityProvenance([[...$quantity, 'source' => 'estimated']], $facts))->binding(
            [...$intent, 'work_key' => 'finish.floor'])['eligible']);
    }

    public function test_unrelated_measurements_unknown_entities_dimensions_and_missing_lineage_are_rejected(): void
    {
        $facts = [['id' => 'floor:area', 'entity_id' => 'room:1', 'value' => '40', 'unit' => 'm2'],
            ['id' => 'other:area', 'entity_id' => 'room:2', 'value' => '80', 'unit' => 'm2']];
        $quantity = ['id' => 'quantity:area', 'entity_id' => 'room:1', 'value' => '40', 'unit' => 'm2', 'operand_fact_ids' => ['floor:area']];
        $intent = ['entity_id' => 'room:1', 'derived_quantity_id' => 'quantity:area', 'unit' => 'm2', 'source_fact_ids' => ['floor:area']];
        foreach ([[[...$intent, 'entity_id' => 'room:missing'], $quantity], [[...$intent, 'entity_id' => 'room:2'], $quantity],
            [[...$intent, 'unit' => 'm3'], $quantity], [[...$intent, 'source_fact_ids' => ['other:area']], $quantity],
            [$intent, [...$quantity, 'operand_fact_ids' => []]], [$intent, [...$quantity, 'operand_fact_ids' => ['missing']]]] as [$input, $measure]) {
            try {
                (new ComposedQuantityProvenance([$measure], $facts))->binding($input);
                self::fail('An unrelated measurement became a work volume.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_auditor_repairs_cannot_rebind_a_floor_area_to_walls_or_another_room(): void
    {
        $quantity = ['id' => 'quantity:floor', 'entity_id' => 'room:1', 'value' => '40', 'unit' => 'm2',
            'formula_key' => 'direct_floor_area', 'source' => 'evidenced', 'operand_fact_ids' => ['fact:area']];
        $proof = new ComposedQuantityProvenance([$quantity], [['id' => 'fact:area', 'entity_id' => 'room:1', 'value' => '40']]);
        foreach ([['operation' => 'add_work', 'work_key' => 'finish.paint', 'source_fact_ids' => ['fact:area']],
            ['operation' => 'replace_quantity', 'item' => ['key' => 'finish.paint', 'metadata' => ['scope_entity_id' => 'room:1']]],
            ['operation' => 'replace_unit', 'item' => ['key' => 'finish.floor', 'metadata' => ['scope_entity_id' => 'room:2']]],
            ['operation' => 'replace_quantity', 'item' => ['key' => 'finish.floor']]] as $case) {
            try {
                $proof->correction([...$case, 'derived_quantity_id' => 'quantity:floor'], $case['item'] ?? null);
                self::fail('An audit repair used an unrelated quantity.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_canonical_basis_cannot_reuse_a_recipe_from_another_measurement(): void
    {
        $quantity = ['id' => 'quantity:floor', 'entity_id' => 'room:1', 'value' => '40', 'unit' => 'm2',
            'formula_key' => 'direct_floor_area', 'source' => 'evidenced', 'operand_fact_ids' => ['fact:area']];
        $intent = ['work_key' => 'finish.floor', 'derived_quantity_id' => 'quantity:floor', 'source_fact_ids' => ['fact:area']];
        $basis = ['amount' => '40', 'unit' => 'm2', 'formula_key' => 'direct_floor_area', 'source' => 'evidenced',
            'formula_inputs' => ['entity_id' => 'room:1', 'operands' => [['fact_id' => 'fact:area']]]];
        foreach ([null, [...$basis, 'amount' => '80'], [...$basis, 'unit' => '100 m2'],
            [...$basis, 'formula_inputs' => ['entity_id' => 'room:2', 'operands' => [['fact_id' => 'fact:area']]]],
            [...$basis, 'formula_inputs' => ['entity_id' => 'room:1', 'operands' => [['fact_id' => 'fact:other']]]]] as $wrong) {
            $proof = new ComposedQuantityProvenance([[...$quantity, 'quantity_basis' => $wrong]], [['id' => 'fact:area', 'value' => '40']]);
            try {
                $proof->basis($proof->binding($intent));
                self::fail('A stale canonical recipe was accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
