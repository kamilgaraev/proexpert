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
        if ($candidates === []) {
            throw new InvalidArgumentException('estimate_composer_work_candidates_empty');
        }

        return $candidates;
    }

    /** @param list<array<string, mixed>> $intents */
    public function attach(array $localEstimates, array $intents): array
    {
        $byId = [];
        foreach ($intents as $intent) {
            if (! is_array($intent) || ! is_string($intent['candidate_id'] ?? null)
                || isset($byId[$intent['candidate_id']])) {
                throw new InvalidArgumentException('estimate_composer_intent_projection_invalid');
            }
            $byId[$intent['candidate_id']] = $intent;
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

        return $localEstimates;
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
