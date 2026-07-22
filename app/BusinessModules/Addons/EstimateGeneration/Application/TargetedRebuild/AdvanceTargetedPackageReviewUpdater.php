<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\AdvanceEstimateGeneration;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;

final readonly class AdvanceTargetedPackageReviewUpdater implements TargetedPackageReviewUpdater
{
    public function __construct(private AdvanceEstimateGeneration $advance) {}

    public function reviewUpdated(EstimateGenerationSession $session, bool $requiresReview, array $attributes): EstimateGenerationSession
    {
        return $this->advance->reviewUpdated($session, $requiresReview, $attributes);
    }
}
