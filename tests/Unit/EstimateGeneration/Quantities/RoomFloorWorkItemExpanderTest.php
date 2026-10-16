<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Quantities;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateCompositionProjector;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantityData;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantitySource;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\RoomFloorWorkItemExpander;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\WorkItemQuantityMapper;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\WorkItemQuantityResolver;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RoomFloorWorkItemExpanderTest extends TestCase
{
    public function test_three_rooms_bind_each_floor_work_to_its_own_canonical_quantity_and_source(): void
    {
        $quantities = $this->quantities();
        $draft = $this->draft([$this->item('rough.floor'), $this->item('finish.floor'), $this->item('rough.walls')]);
        $original = $draft;
        $expanded = (new RoomFloorWorkItemExpander)->expand($draft, $quantities, 128);
        $items = $expanded['local_estimates'][0]['sections'][0]['work_items'];
        self::assertCount(7, $items);
        self::assertSame($draft['quantity_coverage_warnings'], $expanded['quantity_coverage_warnings']);
        self::assertSame($original, $draft);
        self::assertSame($draft['local_estimates'][0]['sections'][0]['work_items'][2], $items[6]);
        $resolved = [];
        foreach (array_slice($items, 0, 6) as $item) {
            $quantity = (new WorkItemQuantityResolver)->resolve($item, $quantities);
            self::assertNotNull($quantity);
            self::assertSame($item['entity_id'], $quantity->formulaInputs['entity_id']);
            self::assertSame($quantity->key, $item['metadata']['quantity_key']);
            self::assertSame($quantity->key, $item['quantity_formula']);
            self::assertSame($quantity->source->value, $item['metadata']['quantity_source']);
            self::assertSame([['fact_id' => 'fact:'.$item['entity_id']]], $item['source_refs']);
            self::assertFalse($item['skip_normative_matching']);
            self::assertSame('normative_required', $item['pricing_blocker']);
            self::assertSame(['material_assumption'], $item['validation_flags']);
            self::assertArrayNotHasKey('quantity_basis', $item);
            $resolved[$item['metadata']['composition_work_key']][] = $quantity->amount;
        }
        self::assertSame(['8.51', '8.51', '3.33'], $resolved['rough.floor']);
        self::assertSame($resolved['rough.floor'], $resolved['finish.floor']);
        $candidates = (new EstimateCompositionProjector)->candidates($expanded['local_estimates']);
        self::assertCount(7, array_unique(array_column($candidates, 'candidate_id')));
        self::assertSame(array_keys($quantities), array_column(array_slice($candidates, 0, 3), 'quantity_formula'));
        self::assertSame($expanded, (new RoomFloorWorkItemExpander)->expand($draft, array_reverse($quantities, true), 128));
        self::assertSame($expanded, (new RoomFloorWorkItemExpander)->expand($expanded, $quantities, 128));
    }

    public function test_a_global_quantity_and_already_scoped_source_items_are_preserved(): void
    {
        $item = $this->item('rough.floor');
        $draft = $this->draft([$item]);
        $quantity = $this->quantities()['quantity:area:room:1'];
        self::assertSame($draft, (new RoomFloorWorkItemExpander)->expand($draft, ['floor_area' => $quantity], 128));
        foreach (['project_document_normative_reference', 'canonical_technology_package'] as $source) {
            $changed = $draft;
            $changed['local_estimates'][0]['sections'][0]['work_items'][0]['metadata']['generation_source'] = $source;
            self::assertSame($changed, (new RoomFloorWorkItemExpander)->expand($changed, $this->quantities(), 128));
        }
    }

    public function test_an_existing_room_work_is_not_duplicated_by_the_generic_catalog_work(): void
    {
        $existing = $this->item('rough.floor');
        $existing['key'] = 'existing:room:1';
        $existing['metadata']['scope_entity_id'] = 'room:1';
        $draft = $this->draft([$this->item('rough.floor'), $existing]);
        $expanded = (new RoomFloorWorkItemExpander)->expand($draft, $this->quantities(), 128);
        $items = $expanded['local_estimates'][0]['sections'][0]['work_items'];
        self::assertCount(3, $items);
        self::assertSame($existing, $items[2]);
        self::assertSame(['room:2', 'room:3'], array_column(array_slice($items, 0, 2), 'entity_id'));
    }

    public function test_an_existing_entered_amount_or_quantity_evidence_is_not_replaced(): void
    {
        foreach (['amount', 'evidence'] as $kind) {
            $item = $this->item('finish.floor');
            if ($kind === 'amount') {
                $item['quantity'] = '15';
            } else {
                $item['quantity_evidence'] = ['source' => 'evidenced'];
            }
            $draft = $this->draft([$item]);
            self::assertSame($draft, (new RoomFloorWorkItemExpander)->expand($draft, $this->quantities(), 128));
        }
    }

    public function test_unresolved_estimated_and_conflicting_quantities_are_not_published_as_floor_works(): void
    {
        $draft = $this->draft([$this->item('rough.floor')]);
        $quantities = $this->quantities();
        $first = $quantities['quantity:area:room:1'];
        foreach ([[QuantitySource::Estimated, [], '8.51'], [QuantitySource::Evidenced, ['operand_review'], '8.51'],
            [QuantitySource::Evidenced, [], '0']] as [$source, $blockers, $amount]) {
            $invalid = new QuantityData($first->key, $first->unit, $amount, $first->formulaKey,
                $first->formulaVersion, $first->formulaInputs, $source, $first->evidenceIds, $first->modelVersion, [], $blockers);
            self::assertSame($draft, (new RoomFloorWorkItemExpander)->expand($draft, [$invalid->key => $invalid], 128));
        }
        $conflict = new QuantityData('quantity:conflict', 'm2', '8.52', 'floor_area', '1', $first->formulaInputs,
            QuantitySource::Evidenced, $first->evidenceIds, 'model:1');
        self::assertSame($draft, (new RoomFloorWorkItemExpander)->expand($draft, [$first->key => $first, $conflict->key => $conflict], 128));
    }

    public function test_floor_preparation_accepts_only_exact_room_geometry_and_keeps_global_coefficient_fallback_blocked(): void
    {
        $first = $this->quantities()['quantity:area:room:1'];
        $mapper = new WorkItemQuantityMapper;
        self::assertSame($first, $mapper->mapForEntity('rough.floor', [$first], 'room:1'));
        self::assertNull($mapper->mapForEntity('rough.floor', [$first], 'room:other'));
        self::assertNull($mapper->map('rough.floor', ['floor_area' => $first]));
        $estimated = new QuantityData($first->key, 'm2', '8.51', 'floor_area', '1', $first->formulaInputs,
            QuantitySource::Estimated, $first->evidenceIds, 'model:1', ['user_assumption']);
        self::assertNull($mapper->mapForEntity('rough.floor', [$estimated], 'room:1'));
    }

    public function test_the_frozen_global_work_limit_is_checked_before_expanding_candidates(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('estimate_work_items_exceed_approved_plan');
        (new RoomFloorWorkItemExpander)->expand($this->draft([$this->item('rough.floor'), $this->item('finish.floor')]), $this->quantities(), 5);
    }

    private function quantities(): array
    {
        $result = [];
        foreach (['room:1' => '8.51', 'room:2' => '8.51', 'room:3' => '3.33'] as $entity => $amount) {
            $key = 'quantity:area:'.$entity;
            $result[$key] = new QuantityData($key, 'm2', $amount, 'floor_area', '1',
                ['entity_id' => $entity, 'operands' => [['fact_id' => 'fact:'.$entity]]],
                QuantitySource::Evidenced, ['evidence:'.$entity], 'model:1');
        }

        return $result;
    }

    private function item(string $workKey): array
    {
        return ['key' => 'catalog:'.$workKey, 'name' => 'Работа по полу', 'item_type' => 'priced_work',
            'quantity' => null, 'unit' => null, 'source_refs' => [],
            'skip_normative_matching' => true, 'pricing_blocker' => 'quantity_unknown',
            'validation_flags' => ['quantity_unknown', 'unit_requires_confirmation', 'material_assumption'],
            'quantity_basis' => 'Количество отсутствует в исходных данных.',
            'metadata' => ['generation_source' => 'qualitative_work_catalog', 'composition_work_key' => $workKey,
                'quantity_key' => $workKey, 'quantity_source' => 'unknown']];
    }

    private function draft(array $items): array
    {
        return ['local_estimates' => [['key' => 'estimate:1', 'sections' => [['key' => 'floor', 'work_items' => $items]]]],
            'quantity_coverage_warnings' => [['code' => 'canonical_quantity_unresolved', 'entity_id' => 'room:unconfirmed']]];
    }
}
