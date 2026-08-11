<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Planning;

final readonly class CompletenessRule
{
    public function __construct(
        public string $id,
        public string $version,
        public string $contentHash,
        public array $applicabilityFactTypes,
        public string $satisfactionFactType,
        public string $classification,
        public string $severity,
        public string $impact,
        public array $exclusionPolicy,
        public array $workPackage,
    ) {}
}
