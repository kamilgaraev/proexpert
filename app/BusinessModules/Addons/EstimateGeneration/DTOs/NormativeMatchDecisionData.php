<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\DTOs;

final readonly class NormativeMatchDecisionData
{
    /**
     * @param array<int, string> $reasons
     * @param array<int, string> $warnings
     * @param array<string, mixed>|null $candidate
     */
    public function __construct(
        public string $status,
        public bool $canUseForPricing,
        public float $confidence,
        public array $reasons = [],
        public array $warnings = [],
        public ?array $candidate = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'can_use_for_pricing' => $this->canUseForPricing,
            'confidence' => $this->confidence,
            'reasons' => $this->reasons,
            'warnings' => $this->warnings,
            'candidate' => $this->candidate,
        ];
    }
}
