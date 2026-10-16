<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition;

use InvalidArgumentException;

final readonly class EstimateCompositionProjector
{
    /** @return list<array<string, mixed>> */
    public function candidates(array $localEstimates): array
    {
        $candidates = [];
        $seen = [];
        foreach ($localEstimates as $estimate) {
            if (! is_array($estimate)) {
                continue;
            }
            $estimateKey = $this->identifier($estimate['key'] ?? null);
            foreach (is_array($estimate['sections'] ?? null) ? $estimate['sections'] : [] as $section) {
                if (! is_array($section)) {
                    continue;
                }
                $sectionKey = $this->identifier($section['key'] ?? null);
                foreach (is_array($section['work_items'] ?? null) ? $section['work_items'] : [] as $item) {
                    if (! is_array($item) || ($item['item_type'] ?? null) !== 'priced_work') {
                        continue;
                    }
                    $workKey = $this->identifier($item['key'] ?? null);
                    $name = is_string($item['name'] ?? null) ? trim($item['name']) : '';
                    if ($estimateKey === null || $sectionKey === null || $workKey === null
                        || $name === '' || strlen($name) > 300) {
                        throw new InvalidArgumentException('estimate_composer_work_candidate_invalid');
                    }
                    $candidateId = 'work:'.hash('sha256', $estimateKey."\0".$sectionKey."\0".$workKey);
                    if (isset($seen[$candidateId])) {
                        throw new InvalidArgumentException('estimate_composer_work_candidate_duplicate');
                    }
                    $seen[$candidateId] = true;
                    $metadata = is_array($item['metadata'] ?? null) ? $item['metadata'] : [];
                    $candidates[] = [
                        'candidate_id' => $candidateId,
                        'work_key' => $workKey,
                        'name' => $name,
                        'unit' => $this->boundedString($item['unit'] ?? null, 32),
                        'quantity' => $this->positiveDecimal($item['quantity'] ?? null),
                        'quantity_formula' => $this->boundedString(
                            $item['quantity_formula'] ?? $metadata['quantity_key'] ?? null,
                            300,
                        ),
                        'source_fact_ids' => $this->sourceFactIds($item['source_refs'] ?? []),
                        'technology_package_candidate' => $this->identifier($metadata['technology_package_id'] ?? null),
                    ];
                }
            }
        }

        return $candidates;
    }

    /** @param list<array<string, mixed>> $intents @param list<array<string, mixed>> $derivedQuantities @param list<array<string, mixed>> $facts */
    public function apply(array $localEstimates, array $intents, array $derivedQuantities, array $facts = []): array
    {
        $byId = [];
        $supplementary = [];
        foreach ($intents as $intent) {
            if (! is_array($intent) || ! is_string($intent['candidate_id'] ?? null)
                || isset($byId[$intent['candidate_id']]) || isset($supplementary[$intent['candidate_id']])) {
                throw new InvalidArgumentException('estimate_composer_intent_projection_invalid');
            }
            if (($intent['kind'] ?? null) === 'supplementary') {
                $supplementary[$intent['candidate_id']] = $intent;
            } else {
                $byId[$intent['candidate_id']] = $intent;
            }
        }
        $attached = 0;
        foreach ($localEstimates as $estimateIndex => $estimate) {
            if (! is_array($estimate)) {
                continue;
            }
            $estimateKey = $this->identifier($estimate['key'] ?? null);
            foreach (is_array($estimate['sections'] ?? null) ? $estimate['sections'] : [] as $sectionIndex => $section) {
                if (! is_array($section)) {
                    continue;
                }
                $sectionKey = $this->identifier($section['key'] ?? null);
                foreach (is_array($section['work_items'] ?? null) ? $section['work_items'] : [] as $itemIndex => $item) {
                    if (! is_array($item) || ($item['item_type'] ?? null) !== 'priced_work') {
                        continue;
                    }
                    $workKey = $this->identifier($item['key'] ?? null);
                    if ($estimateKey === null || $sectionKey === null || $workKey === null) {
                        throw new InvalidArgumentException('estimate_composer_work_candidate_invalid');
                    }
                    $candidateId = 'work:'.hash('sha256', $estimateKey."\0".$sectionKey."\0".$workKey);
                    if (! isset($byId[$candidateId])) {
                        throw new InvalidArgumentException('estimate_composer_intent_projection_incomplete');
                    }
                    $localEstimates[$estimateIndex]['sections'][$sectionIndex]['work_items'][$itemIndex]['composition_intent'] = $byId[$candidateId];
                    $attached++;
                }
            }
        }
        if ($attached !== count($byId)) {
            throw new InvalidArgumentException('estimate_composer_intent_projection_incomplete');
        }

        if ($supplementary !== []) {
            $positionKeys = $this->supplementaryPositionKeys($localEstimates, $supplementary);
            $groups = [];
            foreach ($supplementary as $id => $intent) {
                $package = isset($intent['work_section']) ? 'ai-composer-supplementary:'.$intent['work_section'] : 'ai-composer-supplementary';
                $groups[$package][$id] = $intent;
            }
            foreach ($groups as $package => $group) {
                $localEstimates[] = [
                    'key' => $package,
                    'title' => trans_message('estimate_generation.composition.supplementary_title'),
                    'scope_type' => 'supplementary',
                    'source_refs' => $this->sourceRefs($group),
                    'sections' => [[
                        'key' => $package,
                        'title' => trans_message('estimate_generation.composition.supplementary_section'),
                        'work_items' => $this->supplementaryItems($group, $derivedQuantities, $facts, $positionKeys),
                    ]],
                ];
            }
        }

        return $localEstimates;
    }

    /** @param array<string, array<string, mixed>> $supplementary @param list<array<string, mixed>> $derivedQuantities @param list<array<string, mixed>> $facts */
    private function supplementaryItems(array $supplementary, array $derivedQuantities, array $facts, array $positionKeys): array
    {
        $quantityProvenance = new ComposedQuantityProvenance($derivedQuantities, $facts);
        $items = [];
        foreach ($supplementary as $candidateId => $intent) {
            $binding = $quantityProvenance->binding($intent);
            $quantity = ($binding['eligible'] ?? false) ? $binding['quantity'] : null;
            $operandIds = $quantity === null ? [] : $binding['operand_fact_ids'];
            $technology = $this->identifier($intent['technology_package_candidate'] ?? null);
            $metadata = ['composition_work_key' => $intent['work_key']];
            if ($technology !== null) {
                $metadata['technology_package_id'] = $technology;
            }
            if (is_array($quantity)) {
                $metadata['quantity_key'] = $intent['derived_quantity_id'];
                $metadata['scope_entity_id'] = $binding['entity_id'];
                $metadata['quantity_operand_fact_ids'] = $operandIds;
            } elseif (is_string($intent['entity_id'] ?? null)) {
                $metadata['scope_entity_id'] = $intent['entity_id'];
            }
            if ($binding !== null && ! $binding['eligible']) {
                $metadata['proposed_quantity_key'] = $intent['derived_quantity_id'];
                $metadata['quantity_review_reason'] = 'work_quantity_purpose_unverified';
            }
            $refs = array_values(array_unique([...$intent['source_fact_ids'], ...$operandIds]));
            $items[] = [
                'key' => $positionKeys[$candidateId],
                'work_key' => $intent['work_key'],
                'name' => $intent['name'],
                'item_type' => 'priced_work',
                'unit' => is_array($quantity)
                    ? $this->boundedString($quantity['unit'] ?? null, 32)
                    : $this->boundedString($intent['unit'] ?? null, 32),
                'quantity' => is_array($quantity)
                    ? $this->positiveDecimal($quantity['value'] ?? null)
                    : null,
                'quantity_formula' => is_array($quantity) ? $this->boundedString($quantity['id'] ?? null, 300) : null,
                'source_refs' => array_map(
                    static fn (string $factId): array => ['fact_id' => $factId,
                        'role' => in_array($factId, $operandIds, true) ? 'quantity_operand' : 'context'],
                    $refs,
                ),
                'validation_flags' => $binding !== null && ! $binding['eligible'] ? ['work_quantity_purpose_unverified'] : [],
                'metadata' => $metadata,
                'composition_intent' => $intent,
            ];
        }

        return $items;
    }

    public function supplementaryPositionKeys(array $localEstimates, array $supplementary): array
    {
        $reserved = [];
        foreach ($localEstimates as $estimate) {
            foreach ($estimate['sections'] ?? [] as $section) {
                foreach ($section['work_items'] ?? [] as $item) {
                    $reserved[$item['key']] = true;
                }
            }
        }
        $counts = array_count_values(array_column($supplementary, 'work_key'));
        $keys = [];
        foreach ($supplementary as $id => $intent) {
            $raw = $intent['work_key'];
            if (! isset($reserved[$raw]) && $counts[$raw] === 1) {
                $keys[$id] = $raw;
                $reserved[$raw] = true;
            } else {
                $salt = 0;
                do {
                    $key = 'supplementary:'.hash('sha256', $id.':'.$salt++);
                } while (isset($reserved[$key]));
                $keys[$id] = $key;
                $reserved[$key] = true;
            }
        }

        return $keys;
    }

    /** @param array<string, array<string, mixed>> $supplementary */
    private function sourceRefs(array $supplementary): array
    {
        $refs = [];
        foreach ($supplementary as $intent) {
            foreach ($intent['source_fact_ids'] as $factId) {
                $refs[$factId] = ['fact_id' => $factId];
            }
        }

        return array_values($refs);
    }

    private function identifier(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,159}$/D', $value) !== 1) {
            return null;
        }

        return $value;
    }

    private function boundedString(mixed $value, int $maxBytes): ?string
    {
        return is_string($value) && trim($value) !== '' && strlen($value) <= $maxBytes ? $value : null;
    }

    private function positiveDecimal(mixed $value): ?string
    {
        return is_string($value)
            && preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,12})?$/D', $value) === 1
            && preg_match('/^0(?:\.0+)?$/D', $value) !== 1
                ? $value
                : null;
    }

    /** @return list<string> */
    private function sourceFactIds(mixed $sourceRefs): array
    {
        if (! is_array($sourceRefs)) {
            return [];
        }
        $factIds = [];
        foreach (array_slice($sourceRefs, 0, 256) as $sourceRef) {
            $factId = is_array($sourceRef) ? $this->identifier($sourceRef['fact_id'] ?? null) : null;
            if ($factId !== null) {
                $factIds[$factId] = true;
            }
        }

        return array_keys($factIds);
    }
}
