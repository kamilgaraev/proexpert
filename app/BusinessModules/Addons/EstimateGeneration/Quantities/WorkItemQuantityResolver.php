<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Quantities;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\FactVocabulary;

final readonly class WorkItemQuantityResolver
{
    public function __construct(
        private PlannedDirectTakeoffQuantityFactory $directTakeoff = new PlannedDirectTakeoffQuantityFactory,
        private WorkItemQuantityMapper $mapper = new WorkItemQuantityMapper,
    ) {}

    public function resolve(array $workItem, array $quantities, array $generationIdentity = []): ?QuantityData
    {
        $entity = $workItem['metadata']['scope_entity_id'] ?? $workItem['entity_id'] ?? null;
        $key = (string) ($workItem['metadata']['quantity_key'] ?? '');
        $work = $workItem['metadata']['composition_work_key'] ?? $key;
        if ((($generationIdentity['evaluation_mode'] ?? null) === 'universal' || WallWorkItemExpander::isAssemblyWork($workItem))
            && in_array($work, WallWorkItemExpander::WORKS, true)
            && (! is_string($entity) || $entity === '' || ! isset($workItem['metadata']['wall_assembly']['fact_id']))) {
            return null;
        }
        if (OpeningWorkItemExpander::isOpeningWork($workItem)
            && in_array($work, OpeningWorkItemExpander::WORKS, true)
            && (! is_string($entity) || $entity === '' || ! isset($workItem['metadata']['opening_scope']['fact_id']))) {
            return null;
        }
        $quantity = $this->directTakeoff->make($workItem, $generationIdentity)
            ?? $this->mapper->map($key, $quantities, ($generationIdentity['evaluation_mode'] ?? null) !== 'universal'
                && ! WallWorkItemExpander::isAssemblyWork($workItem));
        if ($quantity === null && is_string($entity) && $entity !== '') {
            $quantity = $this->mapper->mapForEntity($key, $quantities, $entity);
        }
        if ($quantity === null) {
            return null;
        }
        if ($quantity->formulaKey === 'opening_area'
            && (($quantity->formulaInputs['opening_scope']['opening_kind'] ?? null) !== match ($work) {
                'openings.windows' => 'window', 'openings.doors' => 'door', default => null,
            } || \App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson::encode($quantity->formulaInputs['opening_scope'] ?? null)
                !== \App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson::encode($workItem['metadata']['opening_scope'] ?? null))) {
            return null;
        }
        $sourceEntity = $quantity->formulaInputs['entity_id'] ?? null;
        if (($entity !== null && (! is_string($entity) || $entity === '' || $sourceEntity !== $entity))
            || ((isset(FactVocabulary::engineeringFormulas()[$quantity->formulaKey]) || isset(FactVocabulary::foundationFormulas()[$quantity->formulaKey]))
                && (! is_string($entity) || $entity === ''))) {
            return null;
        }

        return $quantity;
    }
}
