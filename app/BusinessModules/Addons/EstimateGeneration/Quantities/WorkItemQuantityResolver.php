<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Quantities;

final readonly class WorkItemQuantityResolver
{
    public function __construct(
        private PlannedDirectTakeoffQuantityFactory $directTakeoff = new PlannedDirectTakeoffQuantityFactory,
        private WorkItemQuantityMapper $mapper = new WorkItemQuantityMapper,
    ) {}

    public function resolve(array $workItem, array $quantities, array $generationIdentity = []): ?QuantityData
    {
        $quantity = $this->directTakeoff->make($workItem, $generationIdentity)
            ?? $this->mapper->map((string) ($workItem['metadata']['quantity_key'] ?? ''), $quantities);
        if ($quantity === null) {
            return null;
        }
        $entity = $workItem['metadata']['scope_entity_id'] ?? $workItem['entity_id'] ?? null;
        $sourceEntity = $quantity->formulaInputs['entity_id'] ?? null;
        if (($entity !== null && (! is_string($entity) || $entity === '' || $sourceEntity !== $entity))
            || (isset(\App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\FactVocabulary::engineeringFormulas()[$quantity->formulaKey])
                && (! is_string($entity) || $entity === ''))) {
            return null;
        }

        return $quantity;
    }
}
