<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\WorkIntentSourceClassifier;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use InvalidArgumentException;

/** Applicable context is a suggestion, never an accepted work, quantity or physical identity. */
final readonly class EvaluationWorkContextProjector
{
    private array $entities;

    private array $facts;

    private array $evidence;

    public function __construct(ProjectModelSnapshot $snapshot)
    {
        $this->entities = array_column($snapshot->entities, null, 'id');
        $this->facts = array_column($snapshot->facts, null, 'id');
        $this->evidence = array_column($snapshot->evidence, null, 'id');
    }

    public function project(array $item, mixed $packageKey, mixed $sectionKey, string $section): ?array
    {
        $intent = $item['composition_intent'] ?? null;
        $key = $item['key'] ?? null;
        $workKey = $item['work_key'] ?? $key;
        $ids = is_array($intent) ? ($intent['source_fact_ids'] ?? null) : null;
        if (! is_array($intent) || ($intent['kind'] ?? null) !== 'existing'
            || ! is_string($packageKey) || ! is_string($sectionKey) || ! is_string($key) || ! is_string($workKey)
            || ($intent['candidate_id'] ?? null) !== 'work:'.hash('sha256', $packageKey."\0".$sectionKey."\0".$key)
            || ! is_array($ids) || ! array_is_list($ids) || $ids === [] || count($ids) > 256) {
            return null;
        }
        $facts = [];
        foreach ($ids as $id) {
            if (! is_string($id) || isset($facts[$id]) || ! isset($this->facts[$id])) {
                return null;
            }
            $fact = $this->facts[$id];
            $entity = $this->entities[$fact->entityId] ?? null;
            if ($entity === null) {
                return null;
            }
            $proofIds = [];
            foreach ($fact->evidenceIds as $proofId) {
                $proof = $this->evidence[$proofId] ?? null;
                if ($proof !== null && $proof->sourceVersion === $fact->sourceVersion
                    && $proof->sourceType === ($fact->origin === 'document' ? 'document' : 'user_input')) {
                    $proofIds[] = $proofId;
                }
            }
            $facts[$id] = ['id' => $id, 'type' => $fact->type, 'entity_id' => $fact->entityId,
                'entity_type' => $entity->attributes['semantic_type'] ?? $entity->attributes['properties']['semantic_type'] ?? $entity->type,
                'value' => $fact->value, 'origin' => $fact->origin, 'status' => $fact->status, 'evidence_ids' => $proofIds];
        }
        try {
            $binding = (new WorkIntentSourceClassifier)->binding(['work_key' => $workKey, 'source_fact_ids' => $ids], array_values($facts));
        } catch (InvalidArgumentException) {
            return null;
        }
        if ($binding['work_section'] !== $section) {
            return null;
        }
        $entity = $this->entities[$binding['entity_id']];
        $entityType = $entity->attributes['semantic_type'] ?? $entity->attributes['properties']['semantic_type'] ?? $entity->type;
        $name = $entity->attributes['name'] ?? $entity->attributes['identity']['label'] ?? null;
        $identityNames = [];
        foreach ($binding['basis_fact_ids'] as $id) {
            $fact = $this->facts[$id];
            if ($fact->type === 'entity_identity' && $fact->entityId === $entity->id && is_array($fact->value)
                && (! isset($fact->value['entity_id']) || $fact->value['entity_id'] === $entity->id)
                && (! isset($fact->value['entity_type']) || $fact->value['entity_type'] === $entityType)
                && is_string($fact->value['name'] ?? null) && trim($fact->value['name']) !== '') {
                $identityNames[] = trim($fact->value['name']);
            }
        }
        $identityNames = array_values(array_unique($identityNames));
        if ($identityNames !== []) {
            $name = count($identityNames) === 1 ? $identityNames[0] : null;
        }

        return ['status' => 'proposed', 'entity_id' => $entity->id, 'entity_type' => $entityType,
            'entity_name' => is_string($name) && trim($name) !== '' ? mb_substr(trim($name), 0, 160) : null,
            'section' => $section, 'basis_fact_ids' => $binding['basis_fact_ids']];
    }
}
