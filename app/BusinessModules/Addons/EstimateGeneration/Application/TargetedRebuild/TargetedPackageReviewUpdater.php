<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;

interface TargetedPackageReviewUpdater
{
    /** @param array<string, mixed> $attributes */
    public function reviewUpdated(EstimateGenerationSession $session, bool $requiresReview, array $attributes): EstimateGenerationSession;
}
