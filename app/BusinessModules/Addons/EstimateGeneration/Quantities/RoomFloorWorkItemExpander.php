<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Quantities;

use App\BusinessModules\Addons\EstimateGeneration\Application\Credits\FrozenWorkItemLimit;
use Brick\Math\BigDecimal;

final readonly class RoomFloorWorkItemExpander
{
    public function __construct(private WorkItemQuantityMapper $mapper = new WorkItemQuantityMapper) {}

    public function expand(array $draft, array $quantities, ?int $workLimit, array $roomEntityIds = []): array
    {
        $rooms = array_fill_keys($roomEntityIds, true);
        ksort($rooms, SORT_STRING);
        $limit = min($workLimit ?? 10000, 10000);
        $count = 0;
        $existingScopes = $byEntity = [];
        foreach ($draft['local_estimates'] ?? [] as $local) {
            foreach ($local['sections'] ?? [] as $section) {
                foreach ($section['work_items'] ?? [] as $item) {
                    $count++;
                    $entity = $item['metadata']['scope_entity_id'] ?? $item['entity_id'] ?? null;
                    $work = $item['metadata']['composition_work_key'] ?? $item['metadata']['quantity_key'] ?? null;
                    if (is_string($entity) && $entity !== '' && is_string($work)) {
                        $existingScopes[$work][$entity] = true;
                    }
                }
            }
        }
        FrozenWorkItemLimit::assertCount($count, $limit);
        $global = $quantities['floor_area'] ?? null;
        if ($global !== null && (! $global instanceof QuantityData
            || ! is_string($global->formulaInputs['entity_id'] ?? null) || $global->formulaInputs['entity_id'] === '')) {
            return $draft;
        }
        foreach ($quantities as $quantity) {
            if (! $quantity instanceof QuantityData
                || CurrentProjectDerivedQuantityService::formulaPurpose($quantity->formulaKey) !== 'floor_area') {
                continue;
            }
            $entity = $quantity->formulaInputs['entity_id'] ?? null;
            if (is_string($entity) && isset($rooms[$entity])) {
                $byEntity[$entity][] = $quantity;
            }
        }
        ksort($byEntity, SORT_STRING);
        foreach ($draft['local_estimates'] ?? [] as $localIndex => $local) {
            foreach ($local['sections'] ?? [] as $sectionIndex => $section) {
                $items = [];
                foreach ($section['work_items'] ?? [] as $item) {
                    $work = $item['metadata']['composition_work_key'] ?? $item['metadata']['quantity_key'] ?? null;
                    if (($item['item_type'] ?? null) !== 'priced_work'
                        || ($item['metadata']['generation_source'] ?? null) !== 'qualitative_work_catalog'
                        || ($item['metadata']['quantity_source'] ?? null) !== 'unknown'
                        || ($item['quantity'] ?? null) !== null || isset($item['quantity_evidence'])
                        || isset($item['metadata']['scope_entity_id']) || isset($item['entity_id'])
                        || ! in_array($work, ['rough.floor', 'finish.floor'], true)) {
                        $items[] = $item;

                        continue;
                    }
                    $matches = [];
                    foreach ($byEntity as $entity => $roomQuantities) {
                        $quantity = $this->mapper->mapForEntity($work, $roomQuantities, $entity);
                        if ($quantity !== null && BigDecimal::of($quantity->amount)->isGreaterThan(0)) {
                            $matches[$entity] = $quantity;
                        }
                    }
                    if ($matches === []) {
                        $items[] = $item;

                        continue;
                    }
                    $newScopes = array_diff_key($rooms, $existingScopes[$work] ?? []);
                    $count += count($newScopes) - 1;
                    FrozenWorkItemLimit::assertCount($count, $limit);
                    foreach (array_keys($newScopes) as $entity) {
                        $scoped = $item;
                        $scoped['key'] = 'room-floor:'.hash('sha256', $item['key']."\0".$entity);
                        if (isset($scoped['logical_key'])) {
                            $scoped['logical_key'] = $scoped['key'];
                        }
                        $scoped['entity_id'] = $entity;
                        $scoped['metadata']['scope_entity_id'] = $entity;
                        $scoped['metadata']['composition_work_key'] = $work;
                        $existingScopes[$work][$entity] = true;
                        if (! isset($matches[$entity])) {
                            $items[] = $scoped;

                            continue;
                        }
                        $quantity = $matches[$entity];
                        $scoped['quantity_formula'] = $quantity->key;
                        $scoped['skip_normative_matching'] = false;
                        if (($scoped['pricing_blocker'] ?? null) === 'quantity_unknown') {
                            $scoped['pricing_blocker'] = 'normative_required';
                        }
                        $scoped['validation_flags'] = array_values(array_filter($scoped['validation_flags'] ?? [],
                            static fn (string $flag): bool => ! in_array($flag, ['quantity_unknown', 'unit_requires_confirmation'], true)));
                        unset($scoped['quantity_basis'], $scoped['quantity_basis_details'], $scoped['quantity_status']);
                        $scoped['metadata']['quantity_key'] = $quantity->key;
                        $scoped['metadata']['quantity_source'] = $quantity->source->value;
                        $refs = is_array($scoped['source_refs'] ?? null) ? $scoped['source_refs'] : [];
                        $factIds = array_fill_keys(array_column($refs, 'fact_id'), true);
                        foreach ($quantity->formulaInputs['operands'] ?? [] as $operand) {
                            $factId = $operand['fact_id'] ?? null;
                            if (is_string($factId) && $factId !== '' && ! isset($factIds[$factId])) {
                                $refs[] = ['fact_id' => $factId];
                                $factIds[$factId] = true;
                            }
                        }
                        $scoped['source_refs'] = $refs;
                        $items[] = $scoped;
                    }
                }
                $draft['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'] = $items;
            }
        }

        return $draft;
    }
}
