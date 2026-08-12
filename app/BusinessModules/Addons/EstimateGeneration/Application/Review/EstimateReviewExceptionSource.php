<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Review;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;

interface EstimateReviewExceptionSource
{
    /** @return array{items: array<int, array<string, mixed>>, truncated: bool} */
    public function current(EstimateGenerationSession $session, int $limit): array;
}
