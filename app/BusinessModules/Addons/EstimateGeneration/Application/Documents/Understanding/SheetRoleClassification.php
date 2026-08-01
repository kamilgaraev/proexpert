<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents\Understanding;

final readonly class SheetRoleClassification
{
    public function __construct(
        public SheetRole $role,
        public ?string $sourceRole,
        public float $confidence,
        public string $inferenceReason,
        public ?string $reanalysisReason,
    ) {
    }

    public function requiresTargetedReanalysis(): bool
    {
        return $this->reanalysisReason !== null;
    }
}
