<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Planning;

final readonly class CompletenessFinding
{
    public function __construct(
        public string $ruleId,
        public string $ruleVersion,
        public string $ruleHash,
        public string $classification,
        public string $status,
        public string $severity,
        public string $impact,
        public float $confidence,
        public array $evidenceFactIds,
        public array $relatedEntityIds,
        public array $relatedFactTypes,
        public array $exclusionPolicy,
        public ?array $exclusionDecision,
        public ?TechnologyWorkPackage $workPackage,
    ) {}

    public function toArray(): array
    {
        return [...get_object_vars($this), 'workPackage' => $this->workPackage?->toArray()];
    }
}
