<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Quality;

final readonly class EstimateGenerationQualityReviewDecision
{
    /** @param list<string> $reasons */
    public function __construct(
        public bool $requiresReview,
        public array $reasons,
    ) {}
}
