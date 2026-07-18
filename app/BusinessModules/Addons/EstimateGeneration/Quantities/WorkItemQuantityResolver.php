<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Quantities;

final readonly class WorkItemQuantityResolver
{
    public function __construct(
        private PlannedDirectTakeoffQuantityFactory $directTakeoff = new PlannedDirectTakeoffQuantityFactory,
        private WorkItemQuantityMapper $mapper = new WorkItemQuantityMapper,
    ) {}

    public function resolve(array $workItem, array $quantities): ?QuantityData
    {
        return $this->directTakeoff->make($workItem)
            ?? $this->mapper->map((string) ($workItem['metadata']['quantity_key'] ?? ''), $quantities);
    }
}
