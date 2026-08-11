<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Planning;

final readonly class TechnologyWorkPackage
{
    public function __construct(
        public string $id,
        public array $works,
        public array $materials,
        public array $machinery,
        public array $normIntents,
        public array $quantityFormulas,
        public array $dependencies,
        public array $regionalPriceAvailability,
        public array $assumptions,
        public array $risks,
        public array $provenance,
    ) {}

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
