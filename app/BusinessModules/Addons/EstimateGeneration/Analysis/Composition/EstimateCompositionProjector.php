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
            $localEstimates[] = [
                'key' => 'ai-composer-supplementary',
                'title' => trans_message('estimate_generation.composition.supplementary_title'),
                'scope_type' => 'supplementary',
                'source_refs' => $this->sourceRefs($supplementary),
                'sections' => [[
                    'key' => 'ai-composer-supplementary',
                    'title' => trans_message('estimate_generation.composition.supplementary_section'),
                    'work_items' => $this->supplementaryItems($supplementary, $derivedQuantities, $facts),
                ]],
            ];
        }

        return $localEstimates;
    }

    /** @param array<string, array<string, mixed>> $supplementary @param list<array<string, mixed>> $derivedQuantities @param list<array<string, mixed>> $facts */
    private function supplementaryItems(array $supplementary, array $derivedQuantities, array $facts): array
    {
        $quantities = [];
        foreach ($derivedQuantities as $quantity) {
            if (is_array($quantity) && is_string($quantity['id'] ?? null)) {
                $quantities[$quantity['id']] = $quantity;
            }
        }
        $factsById = [];
        foreach ($facts as $fact) {
            if (is_array($fact) && is_string($fact['id'] ?? null)) {
                $factsById[$fact['id']] = $fact;
            }
        }
        $items = [];
        foreach ($supplementary as $intent) {
            $quantity = is_string($intent['derived_quantity_id'] ?? null)
                ? ($quantities[$intent['derived_quantity_id']] ?? null)
                : null;
            $sourceFact = null;
            foreach ($intent['source_fact_ids'] as $factId) {
                $candidate = $factsById[$factId] ?? null;
                if (is_array($candidate)
                    && ($candidate['status'] ?? null) === 'confirmed'
                    && (is_int($candidate['value'] ?? null) || is_float($candidate['value'] ?? null))
                    && (float) $candidate['value'] > 0
                    && is_string($candidate['unit'] ?? null)) {
                    $sourceFact = $candidate;
                    break;
                }
            }
            $technology = $this->identifier($intent['technology_package_candidate'] ?? null);
            $metadata = [];
            if ($technology !== null) {
                $metadata['technology_package_id'] = $technology;
            }
            if (is_array($quantity)) {
                $metadata['quantity_key'] = $intent['derived_quantity_id'];
            }
            $items[] = [
                'key' => $intent['work_key'],
                'name' => $intent['name'],
                'item_type' => 'priced_work',
                'unit' => is_array($quantity)
                    ? $this->boundedString($quantity['unit'] ?? null, 32)
                    : $this->boundedString($sourceFact['unit'] ?? null, 32),
                'quantity' => is_array($quantity)
                    ? $this->positiveDecimal($quantity['value'] ?? null)
                    : $this->positiveDecimal($sourceFact['value'] ?? null),
                'quantity_formula' => is_array($quantity) ? $this->boundedString($quantity['id'] ?? null, 300) : null,
                'source_refs' => array_map(
                    static fn (string $factId): array => ['fact_id' => $factId],
                    $intent['source_fact_ids'],
                ),
                'metadata' => $metadata,
                'composition_intent' => $intent,
            ];
        }

        return $items;
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
