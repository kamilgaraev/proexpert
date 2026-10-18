<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit;

use App\BusinessModules\Addons\EstimateGeneration\Observability\EstimatorTextPayloadBudget;
use App\BusinessModules\Addons\EstimateGeneration\Observability\EstimatorTextWirePolicy;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/** Preserve all facts/evidence across parts, including facts not yet used by a work. */
final class EstimateAuditContextPlanner
{
    private array $lastFailedPartSizes = [];

    private array $failedWorkKeys = [];

    private bool $failedSingleton = false;

    public function __construct(private ?array $wirePolicy = null) {}

    public function prepare(EstimateAuditInput $root, int $maximumBytes): array
    {
        $this->lastFailedPartSizes = [];
        $quantityIndex = $this->quantityIndex($root->derivedQuantities);
        $quantityIndex['records'] = (new EstimateAuditDraftRecordPlanner)->quantityRecordIndex($root->draft);
        if ($root->contractVersion === 'estimate-auditor:v3') {
            $parts = (new EstimateAuditBatchPlanner)->plan($root->draft);
            for ($attempt = 0; $attempt < 6; $attempt++) {
                $inputs = $this->prepareParts($root, $parts, $maximumBytes, $quantityIndex);
                if ($inputs !== null) {
                    return $inputs;
                }
                $next = [];
                foreach ($parts as $part) {
                    $count = count($part['work_keys']);
                    if ($count > 1 && ($this->failedSingleton || array_intersect($part['work_keys'], array_keys($this->failedWorkKeys)) !== [])) {
                        array_push($next, ...(new EstimateAuditBatchPlanner)->plan($part['draft'], (int) ceil($count / 2)));
                    } else {
                        $next[] = $part;
                    }
                }
                if (count($next) === count($parts) || count($next) > EstimateAuditBatchPlanner::MAX_PARTS) {
                    break;
                }
                $parts = $next;
            }
        }
        for ($size = EstimateAuditBatchPlanner::WORKS_PER_PART; $size >= 1; $size = intdiv($size, 2)) {
            $parts = (new EstimateAuditBatchPlanner)->plan($root->draft, $size);
            $inputs = $this->prepareParts($root, $parts, $maximumBytes, $quantityIndex);
            if ($inputs !== null) {
                return $inputs;
            }
        }

        $this->reportUnsplitContext($root, $maximumBytes);

        throw new InvalidArgumentException('estimate_audit_context_requires_source_split');
    }

    private function quantityIndex(array $quantities): array
    {
        $index = ['ids' => [], 'bases' => [], 'bytes' => []];
        $wireQuantities = EstimateAuditQuantityWireProjection::project($quantities);
        foreach ($quantities as $position => $quantity) {
            $id = $quantity['id'] ?? null;
            if (! is_string($id)) {
                continue;
            }
            $index['ids'][$id] = true;
            $index['bytes'][$id] = strlen(CanonicalPipelineJson::encode($wireQuantities[$position]));
            if (is_array($quantity['quantity_basis'] ?? null) && $quantity['quantity_basis'] !== []) {
                $index['bases'][CanonicalPipelineJson::encode($quantity['quantity_basis'])][] = $id;
            }
        }

        return $index;
    }

    private function prepareParts(EstimateAuditInput $root, array $parts, int $maximumBytes, array $quantityIndex): ?array
    {
        $full = $root->canonicalPayload(false);
        $allFit = true;
        foreach ($parts as $part) {
            $payload = [...$full, 'draft' => EstimateAuditDraftWireProjection::project($part['draft'], $root->derivedQuantities)];
            if ($part['id'] !== 'whole') {
                $payload['part_id'] = $part['id'];
            }
            if (! $this->fits($payload, $maximumBytes)) {
                $allFit = false;
                break;
            }
        }
        if ($allFit) {
            if (count($parts) === 1) {
                return array_map(static fn (array $part): array => [...$part, 'input' => $root->withPart($part['draft'], $part['id'])], $parts);
            }
            $contexts = array_map(fn (array $part): array => [...$part, 'facts' => $root->facts,
                'derived_quantities' => $root->derivedQuantities, 'evidence' => $root->evidence,
                'entity_ids' => $this->workEntityIds($part['draft'])], $parts);
            $prepared = $this->prepareContexts($root, $contexts, $maximumBytes);
            if ($prepared !== null) {
                return $prepared;
            }
        }
        $factsById = $factBytes = [];
        foreach ($root->facts as $fact) {
            $factsById[$fact['id']] = $fact;
            $factBytes[$fact['id']] = strlen(CanonicalPipelineJson::encode($fact));
        }
        foreach ($root->evidence as $record) {
            $factBytes[$record['fact_id']] += strlen(CanonicalPipelineJson::encode($record));
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
                $quantityId = $item['quantity_formula'] ?? null;
                if (is_string($quantityId) && isset($quantityIndex['ids'][$quantityId])) {
                    $quantityIds[$index][$quantityId] = true;
                }
                $basisQuantityIds = is_array($basis) && $basis !== []
                    ? ($quantityIndex['bases'][CanonicalPipelineJson::encode($basis)] ?? []) : [];
                foreach ($basisQuantityIds as $id) {
                    $quantityIds[$index][$id] = true;
                }
            }
        }
        // Assign unused facts to a deterministic part rather than dropping them.
        // This lets its auditor detect a missing work from an otherwise unused source.
        $loads = [];
        foreach ($parts as $index => $part) {
            $loads[$index] = strlen(CanonicalPipelineJson::encode(EstimateAuditDraftWireProjection::project($part['draft'], $root->derivedQuantities)));
        }
        $assignedQuantityRecords = array_fill(0, count($parts), []);
        foreach ($root->derivedQuantities as $quantity) {
            $id = $quantity['id'] ?? null;
            if (! is_string($id)) {
                throw new InvalidArgumentException('estimate_audit_quantity_identity_invalid');
            }
            foreach ($quantity['operand_fact_ids'] ?? [] as $factId) {
                if (! isset($factsById[$factId])) {
                    throw new InvalidArgumentException('estimate_audit_quantity_dependency_missing');
                }
            }
            foreach ($parts as $index => $_) {
                if (isset($quantityIds[$index][$id])) {
                    $loads[$index] += $quantityIndex['bytes'][$id];
                    foreach ($this->quantityRecords($quantity, $quantityIndex) as $record => $bytes) {
                        if (! isset($assignedQuantityRecords[$index][$record])) {
                            $loads[$index] += $bytes;
                            $assignedQuantityRecords[$index][$record] = true;
                        }
                    }
                    foreach ($quantity['operand_fact_ids'] ?? [] as $factId) {
                        $wanted[$index][$factId] = true;
                    }
                }
            }
        }
        $assignedEntities = [];
        foreach ($root->facts as $fact) {
            foreach ($parts as $index => $_) {
                if (isset($wanted[$index][$fact['id']]) || (is_string($fact['entity_id'] ?? null)
                    && isset($entities[$index][$fact['entity_id']]))) {
                    $wanted[$index][$fact['id']] = true;
                    $loads[$index] += $factBytes[$fact['id']];
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
                    $loads[$index] += $factBytes[$fact['id']];
                }
                $wanted[$index][$fact['id']] = true;
            }
        }
        $assignedQuantityIds = [];
        foreach ($quantityIds as $ids) {
            $assignedQuantityIds += $ids;
        }
        $unusedQuantities = array_values(array_filter($root->derivedQuantities,
            static fn (array $quantity): bool => ! isset($assignedQuantityIds[$quantity['id']])));
        $quantityWeights = [];
        foreach ($unusedQuantities as $quantity) {
            $records = $this->quantityRecords($quantity, $quantityIndex);
            $increments = [];
            foreach (array_keys($loads) as $index) {
                $increments[] = $this->quantityIncrement($quantity, $records, $quantityIndex, $wanted[$index], $assignedQuantityRecords[$index], $factBytes);
            }
            $quantityWeights[$quantity['id']] = max($increments);
        }
        usort($unusedQuantities, static fn (array $left, array $right): int => $quantityWeights[$right['id']] <=> $quantityWeights[$left['id']]);
        foreach ($unusedQuantities as $quantity) {
            $id = $quantity['id'];
            $records = $this->quantityRecords($quantity, $quantityIndex);
            $candidateLoads = $loads;
            foreach (array_keys($candidateLoads) as $index) {
                $candidateLoads[$index] += $this->quantityIncrement($quantity, $records, $quantityIndex, $wanted[$index], $assignedQuantityRecords[$index], $factBytes);
            }
            $owner = array_keys($candidateLoads, min($candidateLoads), true)[0];
            $quantityIds[$owner][$id] = true;
            $loads[$owner] = $candidateLoads[$owner];
            foreach (array_keys($records) as $record) {
                $assignedQuantityRecords[$owner][$record] = true;
            }
            foreach ($quantity['operand_fact_ids'] ?? [] as $factId) {
                $wanted[$owner][$factId] = true;
            }
        }
        $contexts = [];
        foreach ($parts as $index => $part) {
            $id = $part['id'] === 'whole' ? 'part:'.substr(hash('sha256', CanonicalPipelineJson::encode($part['work_keys'])), 0, 40) : $part['id'];
            $facts = array_values(array_filter($root->facts, static fn (array $fact): bool => isset($wanted[$index][$fact['id']])));
            $quantities = array_values(array_filter($root->derivedQuantities, static fn (array $quantity): bool => isset($quantityIds[$index][$quantity['id']])));
            $evidence = array_values(array_filter($root->evidence, static fn (array $record): bool => isset($wanted[$index][$record['fact_id']])));
            $draft = EstimateAuditDraftWireProjection::forContextPart($part['draft'], $root->derivedQuantities, $quantities);
            $contexts[$index] = [...$part, 'id' => $id, 'draft' => $draft, 'facts' => $facts,
                'derived_quantities' => $quantities, 'evidence' => $evidence, 'entity_ids' => array_keys($entities[$index])];
        }

        return $this->prepareContexts($root, $contexts, $maximumBytes);
    }

    private function prepareContexts(EstimateAuditInput $root, array $contexts, int $maximumBytes): ?array
    {
        $this->failedWorkKeys = [];
        $this->failedSingleton = false;
        $full = $root->canonicalPayload(false);
        $manifest = ['contract' => 'estimate-audit-context:v1', 'parent_fingerprint' => $root->fingerprint(),
            'total_facts' => count($root->facts), 'total_evidence' => count($root->evidence),
            'total_derived_quantities' => count($root->derivedQuantities),
            'work_manifest' => array_map(static fn (array $item): array => array_intersect_key($item,
                array_flip(['key', 'name', 'unit', 'entity_id', 'quantity_formula'])), $this->items($root->draft)),
            'scope' => 'part_dependencies_and_assigned_unused_facts'];
        $distribution = (new EstimateAuditDraftRecordPlanner)->distribute($root->draft, $contexts);
        $manifest['draft_record_counts'] = $distribution['counts'];
        $prepared = [];
        foreach ($distribution['parts'] as $part) {
            $payload = [...$full, 'facts' => $part['facts'], 'derived_quantities' => EstimateAuditQuantityWireProjection::project($part['derived_quantities']), 'evidence' => $part['evidence'],
                'draft' => EstimateAuditDraftWireProjection::project($part['draft'], $part['derived_quantities']),
                'part_id' => $part['id'], 'context_manifest' => $manifest];
            $sourceFits = strlen(CanonicalPipelineJson::encode([...$payload,
                'draft' => $part['draft'], 'derived_quantities' => $part['derived_quantities']])) <= 2097152;
            $payload = EstimateAuditExactValueWireProjection::forAudit($payload);
            if (! $sourceFits || ! $this->fits($payload, $maximumBytes)) {
                $this->failedWorkKeys += array_fill_keys($part['work_keys'], true);
                $this->failedSingleton = $this->failedSingleton || count($part['work_keys']) === 1;
                $bytes = static fn (mixed $value): int => strlen(CanonicalPipelineJson::encode([$value])) - 2;
                $encoded = CanonicalPipelineJson::encode(['audit_input' => $payload]);
                $this->lastFailedPartSizes = ['payload_bytes' => strlen($encoded),
                    'serialized_input_upper' => EstimatorTextWirePolicy::serializedInputUpper([
                        ['role' => 'system', 'content' => str_repeat('context ', 1024)],
                        ['role' => 'user', 'content' => $encoded],
                    ]),
                    'field_bytes' => array_map($bytes, $payload), 'draft_field_bytes' => array_map($bytes, $payload['draft'])];

                continue;
            }
            $input = $root->withContextPart($part['draft'], $part['id'], $part['facts'], $part['derived_quantities'], $part['evidence'], $manifest);
            $prepared[] = ['id' => $part['id'], 'work_keys' => $part['work_keys'], 'draft' => $part['draft'], 'input' => $input];
        }

        return $this->failedWorkKeys === [] ? $prepared : null;
    }

    private function quantityRecords(array $quantity, array $index): array
    {
        $records = $index['records'][$quantity['id']] ?? [];
        $key = $quantity['quantity_basis']['key'] ?? null;
        if (is_string($key)) {
            $records += $index['records'][$key] ?? [];
        }

        return $records;
    }

    private function quantityIncrement(array $quantity, array $records, array $index, array $facts, array $assignedRecords, array $factBytes): int
    {
        $bytes = $index['bytes'][$quantity['id']];
        foreach ($records as $record => $size) {
            if (! isset($assignedRecords[$record])) {
                $bytes += $size;
            }
        }
        foreach ($quantity['operand_fact_ids'] ?? [] as $factId) {
            if (! isset($facts[$factId])) {
                $bytes += $factBytes[$factId];
            }
        }

        return $bytes;
    }

    private function workEntityIds(array $draft): array
    {
        $entities = [];
        foreach ($this->items($draft) as $item) {
            $basis = $item['quantity_evidence'] ?? $item['quantity_basis_details'] ?? [];
            foreach ([$item['entity_id'] ?? null, $item['physical_entity_id'] ?? null,
                $item['metadata']['scope_entity_id'] ?? null,
                is_array($basis) ? ($basis['formula_inputs']['entity_id'] ?? null) : null] as $id) {
                if (is_string($id) && $id !== '') {
                    $entities[$id] = true;
                }
            }
        }

        return array_keys($entities);
    }

    private function fits(array $payload, int $maximumBytes): bool
    {
        $encoded = CanonicalPipelineJson::encode(['audit_input' => EstimateAuditExactValueWireProjection::forAudit($payload)]);

        return strlen($encoded) <= $maximumBytes && (new EstimatorTextPayloadBudget)->fits($encoded, $this->wirePolicy);
    }

    private function reportUnsplitContext(EstimateAuditInput $root, int $maximumBytes): void
    {
        $application = Log::getFacadeApplication();
        if ($application === null || ! $application->bound('log')) {
            return;
        }
        $draft = EstimateAuditDraftWireProjection::project($root->draft, $root->derivedQuantities);
        $bytes = static fn (mixed $value): int => strlen(CanonicalPipelineJson::encode([$value])) - 2;
        $largestWork = [];
        $largestBytes = 0;
        foreach ($this->items($draft) as $item) {
            $itemBytes = $bytes($item);
            if ($itemBytes > $largestBytes) {
                $largestBytes = $itemBytes;
                $largestWork = array_map($bytes, $item);
            }
        }
        Log::warning('estimate_generation.audit_context_requires_source_split', [
            'organization_id' => $root->organizationId, 'project_id' => $root->projectId,
            'session_id' => $root->sessionId, 'maximum_part_bytes' => $maximumBytes,
            'maximum_serialized_input_bytes' => $this->wirePolicy['maximum_serialized_input_bytes'] ?? null,
            'draft_field_bytes' => array_map($bytes, $draft), 'largest_work_bytes' => $largestBytes,
            'largest_work_field_bytes' => $largestWork, 'facts_bytes' => $bytes($root->facts),
            'derived_quantities_bytes' => $bytes($root->derivedQuantities), 'evidence_bytes' => $bytes($root->evidence),
            'failed_part_sizes' => $this->lastFailedPartSizes,
        ]);
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
