<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Presentation;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;

interface GeometryReviewPayloadReader
{
    /** @return array<string, mixed> */
    public function handle(EstimateGenerationSession $session, int $page = 1, int $perPage = 20): array;
}
