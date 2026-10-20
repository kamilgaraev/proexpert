<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Quantities;

use App\BusinessModules\Addons\EstimateGeneration\Application\Credits\FrozenWorkItemLimit;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;

final readonly class FoundationWorkItemExpander
{
    public function __construct(private WorkItemQuantityMapper $mapper = new WorkItemQuantityMapper) {}

    public function expand(array $draft, ProjectModelSnapshot $snapshot, ?int $workLimit, array $quantities = [], array $documentVersions = []): array
    {
        $entities = array_filter(GeometryInventory::entities($snapshot, $documentVersions),
            static fn ($entity): bool => in_array(GeometryInventory::type($entity), ['foundation_beam', 'foundation_slab'], true)
                && ! ($entity->attributes['contextual_only'] ?? false) && ! ($entity->attributes['excluded_by_document_note'] ?? false)
                && ! ($entity->attributes['properties']['contextual_only'] ?? false) && ! ($entity->attributes['properties']['excluded_by_document_note'] ?? false));
        if ($entities === []) {
            return $draft;
        }
        $names = $existing = [];
        foreach ($snapshot->facts as $fact) {
            if ($fact->type === 'entity_identity' && $fact->status === 'confirmed' && is_array($fact->value)
                && is_string($fact->value['name'] ?? null) && trim($fact->value['name']) !== '') {
                $names[$fact->entityId] = mb_substr($fact->value['name'], 0, 160);
            }
        }
        $count = 0;
        foreach ($draft['local_estimates'] ?? [] as $local) {
            foreach ($local['sections'] ?? [] as $section) {
                foreach ($section['work_items'] ?? [] as $item) {
                    $count++;
                    $entityId = $item['metadata']['scope_entity_id'] ?? $item['entity_id'] ?? null;
                    if (($item['metadata']['composition_work_key'] ?? $item['metadata']['quantity_key'] ?? null) === 'foundation.concrete'
                        && is_string($entityId) && $entityId !== '') {
                        $existing[$entityId] = true;
                    }
                }
            }
        }
        FrozenWorkItemLimit::assertCount($count, $workLimit);
        foreach ($draft['local_estimates'] ?? [] as $localIndex => $local) {
            foreach ($local['sections'] ?? [] as $sectionIndex => $section) {
                $items = [];
                foreach ($section['work_items'] ?? [] as $item) {
                    if (($item['metadata']['composition_work_key'] ?? $item['metadata']['quantity_key'] ?? null) !== 'foundation.concrete'
                        || ($item['metadata']['generation_source'] ?? null) !== 'qualitative_work_catalog'
                        || ($item['metadata']['quantity_source'] ?? null) !== 'unknown'
                        || ($item['item_type'] ?? null) !== 'priced_work' || ($item['quantity'] ?? null) !== null
                        || isset($item['quantity_evidence']) || isset($item['metadata']['scope_entity_id']) || isset($item['entity_id'])) {
                        $items[] = $item;

                        continue;
                    }
                    $newEntities = array_diff_key($entities, $existing);
                    $count += count($newEntities) - 1;
                    FrozenWorkItemLimit::assertCount($count, $workLimit);
                    foreach ($newEntities as $id => $entity) {
                        $row = $item;
                        $row['key'] = 'foundation-work:'.hash('sha256', $item['key']."\0".$id);
                        if (isset($row['logical_key'])) {
                            $row['logical_key'] = $row['key'];
                        }
                        $name = $names[$id] ?? $entity->attributes['name'] ?? $entity->attributes['properties']['name'] ?? $entity->stableKey;
                        $row['name'] = $item['name'].' — '.mb_substr((string) $name, 0, 160);
                        $row['unit'] = 'm3';
                        $row['entity_id'] = $id;
                        $row['metadata']['scope_entity_id'] = $id;
                        $row['metadata']['composition_work_key'] = 'foundation.concrete';
                        $row['metadata']['quantity_key'] = 'foundation.concrete';
                        $quantity = $this->mapper->mapForEntity('foundation.concrete', $quantities, $id);
                        if ($quantity !== null) {
                            $row['quantity_formula'] = $quantity->key;
                            $row['metadata']['quantity_key'] = $quantity->key;
                            $row['metadata']['quantity_source'] = $quantity->source->value;
                            $row['pricing_blocker'] = 'normative_required';
                        }
                        $row['validation_flags'] = array_values(array_filter($row['validation_flags'] ?? [],
                            static fn (string $flag): bool => $flag !== 'unit_requires_confirmation'));
                        $existing[$id] = true;
                        $items[] = $row;
                    }
                }
                $draft['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'] = $items;
            }
        }

        return $draft;
    }
}
