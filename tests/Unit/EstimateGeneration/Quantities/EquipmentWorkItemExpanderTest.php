<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Quantities;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateCompositionProjector;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\EquipmentWorkItemExpander;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantityData;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantitySource;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\WorkItemQuantityResolver;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class EquipmentWorkItemExpanderTest extends TestCase
{
    public function test_six_assets_bind_purchase_and_installation_to_their_own_quantity_without_inventing_delivery_or_resources(): void
    {
        $draft = $this->draft([$this->item('equipment.purchase'), $this->item('equipment.install'), $this->item('equipment.delivery')]);
        $original = $draft;
        $quantities = $this->quantities();
        $expanded = $this->expand($draft, $quantities, 13);
        $items = $expanded['local_estimates'][0]['sections'][0]['work_items'];
        self::assertCount(13, $items);
        self::assertSame($original, $draft);
        self::assertSame($original['local_estimates'][0]['sections'][0]['work_items'][2], $items[12]);
        foreach (array_slice($items, 0, 12) as $item) {
            $quantity = (new WorkItemQuantityResolver)->resolve($item, $quantities);
            self::assertNotNull($quantity);
            self::assertSame('1', $quantity->amount);
            self::assertSame($item['entity_id'], $quantity->formulaInputs['entity_id']);
            self::assertSame($quantity->key, $item['quantity_formula']);
            self::assertSame($quantity->key, $item['metadata']['quantity_key']);
            self::assertSame($quantity->source->value, $item['metadata']['quantity_source']);
            self::assertSame([['fact_id' => 'fact:'.$item['entity_id']]], $item['source_refs']);
            self::assertSame(['specification_required'], $item['validation_flags']);
            self::assertTrue($item['skip_normative_matching']);
            self::assertTrue($item['metadata']['requires_specification']);
            self::assertTrue($item['metadata']['requires_technology_confirmation']);
            self::assertSame('normative_required', $item['pricing_blocker']);
            self::assertSame($item['key'], $item['logical_key']);
            self::assertNull($item['quantity']);
            self::assertNull($item['unit_price']);
            foreach (['materials', 'labor', 'machinery', 'other_resources'] as $resourceType) {
                self::assertSame([], $item[$resourceType]);
            }
            self::assertArrayNotHasKey('quantity_basis', $item);
        }
        $candidates = (new EstimateCompositionProjector)->candidates($expanded['local_estimates']);
        self::assertCount(13, array_unique(array_column($candidates, 'candidate_id')));
        self::assertSame($expanded, $this->expand($draft, array_reverse($quantities, true), 13, array_reverse($this->entities())));
        self::assertSame($expanded, $this->expand($expanded, $quantities, 13));
    }

    public function test_only_server_equipment_scope_can_expand_an_existing_residential_intent(): void
    {
        $draft = $this->draft([$this->item('equipment.purchase')]);
        self::assertSame($draft, $this->expand($draft, $this->quantities(), 13, []));
        foreach (['marker', 'source', 'amount', 'evidence', 'scope', 'ordinary'] as $kind) {
            $changed = $draft;
            $item = &$changed['local_estimates'][0]['sections'][0]['work_items'][0];
            match ($kind) {
                'marker' => $item['metadata']['residential_equipment_candidate'] = false,
                'source' => $item['metadata']['generation_source'] = 'project_document_normative_reference',
                'amount' => $item['quantity'] = '6',
                'evidence' => $item['quantity_evidence'] = ['source' => 'evidenced'],
                'scope' => $item['entity_id'] = 'equipment:existing',
                'ordinary' => $item['item_type'] = 'ordinary',
            };
            unset($item);
            self::assertSame($changed, $this->expand($changed, $this->quantities(), 13));
        }
        $empty = $this->draft([]);
        self::assertSame($empty, $this->expand($empty, $this->quantities(), 13));
    }

    public function test_existing_scoped_work_is_preserved_and_not_duplicated_across_sections(): void
    {
        $existing = $this->item('equipment.install');
        $existing['key'] = 'existing:basin';
        $existing['metadata']['scope_entity_id'] = 'equipment:basin';
        $draft = $this->draft([$this->item('equipment.install')]);
        $draft['local_estimates'][0]['sections'][] = ['key' => 'existing', 'work_items' => [$existing]];
        $expanded = $this->expand($draft, $this->quantities(), 6);
        self::assertCount(5, $expanded['local_estimates'][0]['sections'][0]['work_items']);
        self::assertSame([$existing], $expanded['local_estimates'][0]['sections'][1]['work_items']);
        self::assertNotContains('equipment:basin', array_column($expanded['local_estimates'][0]['sections'][0]['work_items'], 'entity_id'));
    }

    public function test_missing_blocked_zero_conflicting_and_unaccepted_estimated_quantities_remain_unknown(): void
    {
        $first = array_values($this->quantities())[0];
        $other = new QuantityData('quantity:foreign', 'count', '9', 'equipment_count', '1',
            ['entity_id' => 'radiator:foreign'], QuantitySource::Evidenced, ['evidence:foreign'], 'model:1');
        $conflict = new QuantityData('quantity:conflict', 'count', '2', $first->formulaKey, '1',
            $first->formulaInputs, QuantitySource::Evidenced, $first->evidenceIds, 'model:1');
        $variants = [[], [$other], [$first, $conflict]];
        foreach ([[QuantitySource::Estimated, [], '1'], [QuantitySource::Evidenced, ['operand_review'], '1'],
            [QuantitySource::Evidenced, [], '0'], [QuantitySource::Evidenced, [], '-1']] as [$source, $blockers, $amount]) {
            $variants[] = [new QuantityData($first->key, 'count', $amount, $first->formulaKey, '1',
                $first->formulaInputs, $source, $first->evidenceIds, 'model:1', [], $blockers)];
        }
        foreach ($variants as $quantities) {
            $expanded = $this->expand($this->draft([$this->item('equipment.install')]), $quantities, 1, [$first->formulaInputs['entity_id']]);
            $item = $expanded['local_estimates'][0]['sections'][0]['work_items'][0];
            self::assertSame($first->formulaInputs['entity_id'], $item['entity_id']);
            self::assertNull((new WorkItemQuantityResolver)->resolve($item, $quantities));
            self::assertSame('unknown', $item['metadata']['quantity_source']);
            self::assertTrue($item['skip_normative_matching']);
            self::assertSame([], $item['source_refs']);
            self::assertNull($item['quantity']);
            self::assertContains('quantity_unknown', $item['validation_flags']);
        }
    }

    public function test_an_accepted_assumption_keeps_its_estimated_source_and_fact_identity(): void
    {
        $first = array_values($this->quantities())[0];
        $quantity = new QuantityData($first->key, 'count', '1', $first->formulaKey, '1', $first->formulaInputs,
            QuantitySource::Estimated, $first->evidenceIds, 'model:1', ['fact:'.$first->formulaInputs['entity_id']]);
        $expanded = $this->expand($this->draft([$this->item('equipment.purchase')]), [$quantity], 1, [$first->formulaInputs['entity_id']]);
        $item = $expanded['local_estimates'][0]['sections'][0]['work_items'][0];
        self::assertSame('estimated', $item['metadata']['quantity_source']);
        self::assertSame($quantity, (new WorkItemQuantityResolver)->resolve($item, [$quantity->key => $quantity]));
        self::assertSame([['fact_id' => $quantity->assumptions[0]]], $item['source_refs']);
    }

    public function test_expansion_does_not_raise_the_frozen_global_work_limit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('estimate_work_items_exceed_approved_plan');
        $this->expand($this->draft([$this->item('equipment.purchase'), $this->item('equipment.install'), $this->item('equipment.delivery')]), $this->quantities(), 12);
    }

    public function test_an_accepted_count_does_not_enable_a_generic_normative_search_before_equipment_specification(): void
    {
        $expanded = $this->expand($this->draft([$this->item('equipment.install')]), $this->quantities(), 6);
        foreach ($expanded['local_estimates'][0]['sections'][0]['work_items'] as $item) {
            self::assertNotNull((new WorkItemQuantityResolver)->resolve($item, $this->quantities()));
            self::assertTrue($item['skip_normative_matching']);
            self::assertTrue($item['metadata']['requires_specification']);
            self::assertTrue($item['metadata']['requires_technology_confirmation']);
        }
    }

    private function expand(array $draft, array $quantities, ?int $limit, ?array $entities = null): array
    {
        return (new EquipmentWorkItemExpander)->expand($draft, $quantities, $limit, $entities ?? $this->entities());
    }

    private function entities(): array
    {
        return ['equipment:basin', 'equipment:bath', 'equipment:geyser', 'equipment:kitchen', 'equipment:panel', 'equipment:wc'];
    }

    private function quantities(): array
    {
        $result = [];
        foreach ($this->entities() as $entity) {
            $key = 'quantity:'.$entity;
            $result[$key] = new QuantityData($key, 'count', '1', 'equipment_count', '1',
                ['entity_id' => $entity, 'operands' => [['fact_id' => 'fact:'.$entity]]],
                QuantitySource::Evidenced, ['evidence:'.$entity], 'model:1');
        }

        return $result;
    }

    private function item(string $work): array
    {
        return ['key' => 'catalog:'.$work, 'logical_key' => 'catalog:'.$work, 'name' => 'Оборудование',
            'item_type' => 'priced_work', 'quantity' => null, 'unit' => null, 'unit_price' => null,
            'materials' => [], 'labor' => [], 'machinery' => [], 'other_resources' => [], 'source_refs' => [],
            'skip_normative_matching' => true, 'pricing_blocker' => 'quantity_unknown',
            'validation_flags' => ['quantity_unknown', 'unit_requires_confirmation', 'specification_required'],
            'quantity_basis' => 'Количество отсутствует.',
            'metadata' => ['generation_source' => 'qualitative_work_catalog', 'composition_work_key' => $work,
                'quantity_key' => $work, 'quantity_source' => 'unknown', 'residential_equipment_candidate' => true,
                'requires_specification' => true, 'requires_technology_confirmation' => true]];
    }

    private function draft(array $items): array
    {
        return ['local_estimates' => [['key' => 'equipment', 'sections' => [['key' => 'equipment', 'work_items' => $items]]]]];
    }
}
