<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents\Understanding;

final readonly class SheetAnalysisRoutingResult
{
    public function __construct(
        public SheetRoleClassification $classification,
        public int $maxFacts,
        public int $maxElements,
        public int $maxOutputTokens,
    ) {
    }

    /** @return array{role: string, source_role: ?string, confidence: float, inference_reason: string, targeted_reanalysis: bool, reanalysis_reason: ?string, max_facts: int, max_elements: int, max_output_tokens: int} */
    public function toArray(): array
    {
        return [
            'role' => $this->classification->role->value,
            'source_role' => $this->classification->sourceRole,
            'confidence' => $this->classification->confidence,
            'inference_reason' => $this->classification->inferenceReason,
            'targeted_reanalysis' => $this->classification->requiresTargetedReanalysis(),
            'reanalysis_reason' => $this->classification->reanalysisReason,
            'max_facts' => $this->maxFacts,
            'max_elements' => $this->maxElements,
            'max_output_tokens' => $this->maxOutputTokens,
        ];
    }
}
