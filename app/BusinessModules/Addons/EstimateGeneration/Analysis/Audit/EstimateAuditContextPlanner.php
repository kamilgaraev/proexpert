<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit;

use App\BusinessModules\Addons\EstimateGeneration\Observability\EstimatorTextPayloadBudget;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use InvalidArgumentException;

/** Preserve all facts/evidence across parts, including facts not yet used by a work. */
final class EstimateAuditContextPlanner
{
    public function __construct(private ?array $wirePolicy = null) {}

    public function prepare(EstimateAuditInput $root, int $maximumBytes): array
    {
        for ($size = EstimateAuditBatchPlanner::WORKS_PER_PART; $size >= 1; $size = intdiv($size, 2)) {
            $parts = (new EstimateAuditBatchPlanner)->plan($root->draft, $size);
            $inputs = $this->prepareParts($root, $parts, $maximumBytes);
            if ($inputs !== null) {
                return $inputs;
            }
        }

        throw new InvalidArgumentException('estimate_audit_context_requires_source_split');
    }

    private function prepareParts(EstimateAuditInput $root, array $parts, int $maximumBytes): ?array
    {
        $full = $root->canonicalPayload();
        $allFit = true;
        foreach ($parts as $part) {
            $payload = [...$full, 'draft' => $part['draft']];
            if ($part['id'] !== 'whole') {
                $payload['part_id'] = $part['id'];
            }
            if (! $this->fits($payload, $maximumBytes)) {
                $allFit = false;
                break;
            }
        }
        if ($allFit) {
            return array_map(static fn (array $part): array => [...$part, 'input' => $root->withPart($part['draft'], $part['id'])], $parts);
        }
        $factsById = [];
        foreach ($root->facts as $fact) {
            $factsById[$fact['id']] = $fact;
        }
        $wanted = $entities = $quantityIds = [];
        foreach ($parts as $index => $part) {
            $wanted[$index] = $entities[$index] = $quantityIds[$index] = [];
            foreach ($this->items($part['draft']) as $item) {
                $basis = $item['quantity_evidence'] ?? $item['quantity_basis_details'] ?? [];
                $quantityEntity = is_array($basis) ? ($basis['formula_inputs']['entity_id'] ?? null) : null;
                if (is_string($quantityEntity)) {
                    $entities[$index][$quantityEntity] = true;
                }
                $basisEvidence = is_array($basis) && is_array($basis['evidence_ids'] ?? null) ? $basis['evidence_ids'] : [];
                foreach ($root->facts as $fact) {
                    if (array_intersect($basisEvidence, $fact['evidence_ids'] ?? []) !== []) {
                        $wanted[$index][$fact['id']] = true;
                        if (is_string($fact['entity_id'] ?? null)) {
                            $entities[$index][$fact['entity_id']] = true;
                        }
                    }
                }
                foreach (['entity_id', 'physical_entity_id'] as $field) {
                    if (is_string($item[$field] ?? null)) {
                        $entities[$index][$item[$field]] = true;
                    }
                }
                $refs = [...($item['source_refs'] ?? []), ...($item['metadata']['stage6_provenance']['source_refs'] ?? [])];
                foreach ($refs as $ref) {
                    $id = is_array($ref) ? ($ref['fact_id'] ?? $ref['id'] ?? null) : null;
                    if (is_string($id) && isset($factsById[$id])) {
                        $wanted[$index][$id] = true;
                        $entity = $factsById[$id]['entity_id'] ?? null;
                        if (is_string($entity)) {
                            $entities[$index][$entity] = true;
                        }
                    }
                }
                foreach ($root->derivedQuantities as $quantity) {
                    if (($quantity['id'] ?? null) === ($item['quantity_formula'] ?? null)) {
                        $quantityIds[$index][$quantity['id']] = true;
                    }
                }
            }
        }
        // Assign unused facts to a deterministic part rather than dropping them.
        // This lets its auditor detect a missing work from an otherwise unused source.
        $loads = array_fill_keys(array_keys($parts), 0);
        $assignedEntities = [];
        foreach ($root->facts as $fact) {
            foreach ($parts as $index => $_) {
                if (isset($wanted[$index][$fact['id']]) || (is_string($fact['entity_id'] ?? null)
                    && isset($entities[$index][$fact['entity_id']]))) {
                    $wanted[$index][$fact['id']] = true;
                    $loads[$index] += strlen(CanonicalPipelineJson::encode($fact));
                }
            }
        }
        foreach ($root->facts as $fact) {
            $owners = [];
            foreach ($parts as $index => $_) {
                if (isset($wanted[$index][$fact['id']]) || (is_string($fact['entity_id'] ?? null)
                    && isset($entities[$index][$fact['entity_id']]))) {
                    $owners[] = $index;
                }
            }
            if ($owners === []) {
                $entity = $fact['entity_id'] ?? null;
                $owners = is_string($entity) && isset($assignedEntities[$entity]) ? $assignedEntities[$entity]
                    : [array_keys($loads, min($loads), true)[0]];
            }
            if (is_string($fact['entity_id'] ?? null)) {
                $assignedEntities[$fact['entity_id']] = $owners;
            }
            foreach ($owners as $index) {
                if (! isset($wanted[$index][$fact['id']])) {
                    $loads[$index] += strlen(CanonicalPipelineJson::encode($fact));
                }
                $wanted[$index][$fact['id']] = true;
            }
        }
        foreach ($root->derivedQuantities as $quantity) {
            $id = $quantity['id'] ?? null;
            if (! is_string($id)) {
                throw new InvalidArgumentException('estimate_audit_quantity_identity_invalid');
            }
            $owners = [];
            foreach ($parts as $index => $_) {
                if (isset($quantityIds[$index][$id]) || (is_string($quantity['entity_id'] ?? null)
                    && isset($entities[$index][$quantity['entity_id']]))) {
                    $owners[] = $index;
                }
            }
            if ($owners === []) {
                $entity = $quantity['entity_id'] ?? null;
                if (is_string($entity) && isset($assignedEntities[$entity])) {
                    $owners = $assignedEntities[$entity];
                } else {
                    foreach ($parts as $index => $_) {
                        foreach ($quantity['operand_fact_ids'] ?? [] as $factId) {
                            if (isset($wanted[$index][$factId])) {
                                $owners[] = $index;
                                break;
                            }
                        }
                    }
                }
                if ($owners === []) {
                    $owners[] = array_keys($loads, min($loads), true)[0];
                }
            }
            foreach ($owners as $index) {
                $quantityIds[$index][$id] = true;
                foreach ($quantity['operand_fact_ids'] ?? [] as $factId) {
                    if (! isset($factsById[$factId])) {
                        throw new InvalidArgumentException('estimate_audit_quantity_dependency_missing');
                    }
                    $wanted[$index][$factId] = true;
                }
            }
        }
        $manifest = ['contract' => 'estimate-audit-context:v1', 'parent_fingerprint' => $root->fingerprint(),
            'total_facts' => count($root->facts), 'total_evidence' => count($root->evidence),
            'total_derived_quantities' => count($root->derivedQuantities),
            'work_manifest' => array_map(static fn (array $item): array => array_intersect_key($item,
                array_flip(['key', 'name', 'unit', 'entity_id', 'quantity_formula'])), $this->items($root->draft)),
            'scope' => 'part_dependencies_and_assigned_unused_facts'];
        $prepared = [];
        foreach ($parts as $index => $part) {
            $id = $part['id'] === 'whole' ? 'part:'.substr(hash('sha256', CanonicalPipelineJson::encode($part['work_keys'])), 0, 40) : $part['id'];
            $facts = array_values(array_filter($root->facts, static fn (array $fact): bool => isset($wanted[$index][$fact['id']])));
            $quantities = array_values(array_filter($root->derivedQuantities, static fn (array $quantity): bool => isset($quantityIds[$index][$quantity['id']])));
            $evidence = array_values(array_filter($root->evidence, static fn (array $record): bool => isset($wanted[$index][$record['fact_id']])));
            $draft = $part['draft'];
            if (isset($draft['estimate_composition']['derived_quantities'])) {
                $draft['estimate_composition']['derived_quantities'] = $quantities;
            }
            $payload = [...$full, 'facts' => $facts, 'derived_quantities' => $quantities, 'evidence' => $evidence,
                'draft' => $draft, 'part_id' => $id, 'context_manifest' => $manifest];
            if (! $this->fits($payload, $maximumBytes)) {
                return null;
            }
            $input = $root->withContextPart($draft, $id, $facts, $quantities, $evidence, $manifest);
            $prepared[] = [...$part, 'id' => $id, 'draft' => $draft, 'input' => $input];
        }

        return $prepared;
    }

    private function fits(array $payload, int $maximumBytes): bool
    {
        $encoded = CanonicalPipelineJson::encode(['audit_input' => $payload]);

        return strlen($encoded) <= $maximumBytes && (new EstimatorTextPayloadBudget)->fits($encoded, $this->wirePolicy);
    }

    private function items(array $draft): array
    {
        $items = [];
        foreach ($draft['local_estimates'] ?? [] as $local) {
            foreach ($local['sections'] ?? [] as $section) {
                array_push($items, ...($section['work_items'] ?? []));
            }
        }

        return $items;
    }
}
