<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;

/** Adds source pointers without changing the acceptance of requirements. */
final class EvaluationCoverageEvidenceProjector
{
    public function project(array $coverage, ProjectModelSnapshot $snapshot): array
    {
        $entities = array_column($snapshot->entities, null, 'id');
        $facts = array_column($snapshot->facts, null, 'id');
        $evidence = array_column($snapshot->evidence, null, 'id');
        foreach (['covered_requirements', 'missing_requirements', 'not_applicable_requirements'] as $key) {
            foreach ($coverage[$key] ?? [] as $index => $row) {
                $entity = $entities[$row['entity_id'] ?? ''] ?? null;
                $name = $entity?->attributes['name'] ?? $entity?->attributes['identity']['label'] ?? null;
                $row['entity_name'] = is_string($name) && trim($name) !== '' ? mb_substr(trim($name), 0, 160) : null;
                $row['sources'] = [];
                foreach ($row['fact_ids'] ?? [] as $id) {
                    $fact = $facts[$id] ?? null;
                    if (! $fact instanceof Fact || $entity === null || $fact->status !== 'confirmed'
                        || ! in_array($fact->origin, ['document', 'user_input'], true)
                        || $fact->organizationId !== $entity->organizationId || $fact->projectId !== $entity->projectId
                        || $fact->sessionId !== $entity->sessionId) {
                        continue;
                    }
                    $locators = [];
                    foreach ($fact->evidenceIds as $proofId) {
                        $proof = $evidence[$proofId] ?? null;
                        if ($fact->origin !== 'document' || ! $proof instanceof Evidence || $proof->sourceType !== 'document'
                            || $proof->sourceVersion !== $fact->sourceVersion
                            || $proof->organizationId !== $fact->organizationId || $proof->projectId !== $fact->projectId
                            || $proof->sessionId !== $fact->sessionId || ($proof->page === null && $proof->nativeReference === null)) {
                            continue;
                        }
                        $reference = $proof->sourceReference ?? $proof->sourceArtifactId;
                        if (preg_match('/\Adocument:([1-9][0-9]*)\z/', $reference, $match) !== 1
                            || (str_starts_with($proof->sourceArtifactId, 'document:') && $proof->sourceArtifactId !== $reference)) {
                            continue;
                        }
                        $documentId = filter_var($match[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                        if ($documentId === false) {
                            continue;
                        }
                        $locators[] = ['source_type' => 'document', 'source_version' => $fact->sourceVersion,
                            'document_id' => $documentId, 'page' => $proof->page, 'region' => $proof->region,
                            'native_reference' => $proof->nativeReference];
                    }
                    $row['sources'][] = ['fact_id' => $fact->id, 'origin' => $fact->origin, 'locators' => $locators];
                }
                $coverage[$key][$index] = $row;
            }
        }

        return $coverage;
    }
}
