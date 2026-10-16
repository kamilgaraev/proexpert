<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition;

use App\BusinessModules\Addons\EstimateGeneration\Services\WorkItemPhysicalIdentity;

final readonly class SupplementaryWorkPhysicalGuard
{
    public function filter(array $localEstimates, array $composition, array $quantities, array $facts): array
    {
        $knownEntities = array_fill_keys(array_filter([...array_column($quantities, 'entity_id'), ...array_column($facts, 'entity_id')], 'is_string'), true);
        $covered = [];
        $rawKeys = [];
        foreach ($localEstimates as $estimate) {
            foreach ($estimate['sections'] ?? [] as $section) {
                foreach ($section['work_items'] ?? [] as $item) {
                    if (($item['item_type'] ?? 'priced_work') !== 'priced_work') {
                        continue;
                    }
                    $identity = $this->identity($item, $knownEntities);
                    $rawKeys[$item['key']][] = $identity;
                    if ($identity !== null) {
                        $covered[serialize($identity)] ??= ['covered_by_position_key' => $item['key']];
                    }
                }
            }
        }
        $provenance = new ComposedQuantityProvenance($quantities, $facts);
        $accepted = [];
        foreach ($composition['intents'] as $index => $intent) {
            if (($intent['kind'] ?? null) !== 'supplementary') {
                $accepted[] = $intent;

                continue;
            }
            $binding = $provenance->binding($intent);
            $identity = $this->identity(['metadata' => [
                'scope_entity_id' => $binding['entity_id'] ?? $intent['entity_id'] ?? null,
                'composition_work_key' => $intent['work_key'],
                'technology_package_id' => $intent['technology_package_candidate'] ?? null,
            ]], $knownEntities);
            $key = $identity === null ? null : serialize($identity);
            if ($key !== null && isset($covered[$key])) {
                $composition['quarantined'][] = ['index' => $index,
                    'reason' => 'estimate_composer_physical_duplicate_covered', 'work_name' => $intent['name'],
                    'next_action' => 'keep_existing_work', 'source_fact_ids' => $intent['source_fact_ids'],
                    ...$covered[$key]];

                continue;
            }
            if (isset($rawKeys[$intent['work_key']]) && ($identity === null
                || in_array(null, $rawKeys[$intent['work_key']], true))) {
                $composition['quarantined'][] = ['index' => $index,
                    'reason' => 'estimate_composer_supplementary_duplicate', 'work_name' => $intent['name'],
                    'next_action' => 'confirm_work_source', 'source_fact_ids' => $intent['source_fact_ids']];

                continue;
            }
            $accepted[] = $intent;
            $rawKeys[$intent['work_key']][] = $identity;
            if ($key !== null) {
                $covered[$key] = ['covered_by_candidate_id' => $intent['candidate_id']];
            }
        }
        $composition['intents'] = $accepted;

        return $composition;
    }

    private function identity(array $item, array $knownEntities): ?array
    {
        $metadata = is_array($item['metadata'] ?? null) ? $item['metadata'] : [];
        $basisEntity = $item['quantity_basis_details']['formula_inputs']['entity_id'] ?? null;
        $evidenceEntity = $item['quantity_evidence']['formula_inputs']['entity_id'] ?? null;
        if ($basisEntity !== null && $evidenceEntity !== null && $basisEntity !== $evidenceEntity) {
            return null;
        }
        $basisEntity ??= $evidenceEntity;
        if (isset($metadata['scope_entity_id'], $basisEntity) && $metadata['scope_entity_id'] !== $basisEntity) {
            return null;
        }
        $metadata['scope_entity_id'] ??= $basisEntity;
        $item['metadata'] = $metadata;
        $identity = WorkItemPhysicalIdentity::fromWorkItem($item);

        return $identity !== null && isset($knownEntities[$identity[0]]) ? $identity : null;
    }
}
