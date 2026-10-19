<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Quantities;

use App\BusinessModules\Addons\EstimateGeneration\Application\Credits\FrozenWorkItemLimit;

final readonly class EquipmentWorkItemExpander
{
    public function __construct(private WorkItemQuantityMapper $mapper = new WorkItemQuantityMapper) {}

    public function expand(array $draft, array $quantities, ?int $workLimit, array $equipmentScopes = []): array
    {
        $equipment = array_filter($equipmentScopes, static fn (mixed $mode): bool => in_array($mode,
            [EquipmentEstimateScope::SUPPLY_AND_INSTALLATION, EquipmentEstimateScope::INSTALLATION_ONLY], true));
        ksort($equipment, SORT_STRING);
        $limit = min($workLimit ?? 10000, 10000);
        $count = 0;
        $existingScopes = [];
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
        if ($equipment === []) {
            return $draft;
        }
        $byEntity = [];
        foreach ($quantities as $quantity) {
            if (! $quantity instanceof QuantityData) {
                continue;
            }
            $entity = $quantity->formulaInputs['entity_id'] ?? null;
            if (is_string($entity) && isset($equipment[$entity])) {
                $byEntity[$entity][] = $quantity;
            }
        }
        foreach ($draft['local_estimates'] ?? [] as $localIndex => $local) {
            foreach ($local['sections'] ?? [] as $sectionIndex => $section) {
                $items = [];
                foreach ($section['work_items'] ?? [] as $item) {
                    $work = $item['metadata']['composition_work_key'] ?? $item['metadata']['quantity_key'] ?? null;
                    if (($item['item_type'] ?? null) !== 'priced_work'
                        || ($item['metadata']['generation_source'] ?? null) !== 'qualitative_work_catalog'
                        || ($item['metadata']['residential_equipment_candidate'] ?? null) !== true
                        || ($item['metadata']['quantity_source'] ?? null) !== 'unknown'
                        || ($item['quantity'] ?? null) !== null || isset($item['quantity_evidence'])
                        || isset($item['metadata']['scope_entity_id']) || isset($item['entity_id'])
                        || ! in_array($work, ['equipment.purchase', 'equipment.install'], true)) {
                        $items[] = $item;

                        continue;
                    }
                    $workEquipment = $work === 'equipment.install' ? $equipment : array_filter($equipment,
                        static fn (string $mode): bool => $mode === EquipmentEstimateScope::SUPPLY_AND_INSTALLATION);
                    $newScopes = array_diff_key($workEquipment, $existingScopes[$work] ?? []);
                    $count += count($newScopes) - 1;
                    FrozenWorkItemLimit::assertCount($count, $limit);
                    foreach (array_keys($newScopes) as $entity) {
                        $scoped = $item;
                        $scoped['key'] = 'equipment-work:'.hash('sha256', $item['key']."\0".$entity);
                        if (isset($scoped['logical_key'])) {
                            $scoped['logical_key'] = $scoped['key'];
                        }
                        $scoped['entity_id'] = $entity;
                        $scoped['metadata']['scope_entity_id'] = $entity;
                        $scoped['metadata']['composition_work_key'] = $work;
                        $existingScopes[$work][$entity] = true;
                        $quantity = $this->mapper->mapForEntity($work, $byEntity[$entity] ?? [], $entity);
                        if ($quantity === null) {
                            $items[] = $scoped;

                            continue;
                        }
                        $scoped['quantity_formula'] = $quantity->key;
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
