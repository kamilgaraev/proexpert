<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;

/** Conservative physical-source binding. Choice ledger writes cannot invalidate their own projection. */
final class EvaluationScenarioSourceBinding
{
    public function fingerprint(ProjectModelSnapshot $snapshot, array $decisions): string
    {
        $entities = array_filter($snapshot->entities, static fn (Entity $entity): bool => ! isset($entity->attributes['identity']['evaluation_anchor'])
            && ! isset($entity->attributes['identity']['work_item_key']));
        $entityIds = array_fill_keys(array_column($entities, 'id'), true);
        $facts = array_filter($snapshot->facts, static fn (Fact $fact): bool => isset($entityIds[$fact->entityId])
            && preg_match('/\Awork_quantity_[a-f0-9]{48}\z/', $fact->type) !== 1);
        $physicalIds = array_fill_keys(array_column($facts, 'entityId'), true);
        $entities = array_filter($entities, static fn (Entity $entity): bool => isset($physicalIds[$entity->id])
            || in_array($entity->type, ['room', 'wall', 'opening'], true));
        $factIds = array_fill_keys(array_column($facts, 'id'), true);
        $decisions = array_filter($decisions, static fn (Decision $decision): bool => isset($factIds[$decision->selectedFactId ?? '']));
        $evidenceIds = [];
        foreach ([...$facts, ...$decisions] as $record) {
            foreach ($record->evidenceIds as $id) {
                $evidenceIds[$id] = true;
            }
        }
        $evidence = array_filter($snapshot->evidence, static fn ($proof): bool => isset($evidenceIds[$proof->id]));
        $parts = ['entities' => $entities, 'facts' => $facts, 'evidence' => $evidence, 'decisions' => $decisions,
            'conflicts' => $snapshot->conflicts];
        foreach ($parts as &$records) {
            $records = array_column($records, null, 'id');
            ksort($records, SORT_STRING);
            $records = array_map(static fn (object $record): array => get_object_vars($record), $records);
        }
        unset($records);

        return hash('sha256', CanonicalPipelineJson::encode(['version' => ParametricEvaluationScenarios::VERSION, ...$parts]));
    }

    public function current(mixed $selection, ProjectModelSnapshot $snapshot, array $decisions): bool
    {
        if (! is_array($selection) || ! array_key_exists('parameter_projection', $selection)) {
            return true;
        }
        $projection = $selection['parameter_projection'];

        return is_array($projection) && ($projection['version'] ?? null) === ParametricEvaluationScenarios::VERSION
            && is_string($projection['source_fingerprint'] ?? null)
            && hash_equals($projection['source_fingerprint'], $this->fingerprint($snapshot, $decisions));
    }
}
