<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Apply;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;

interface GeneratedEstimateWriter
{
    public function createFromSession(
        EstimateGenerationSession $session,
        ApplyGeneratedEstimateCommand $command,
    ): int;

    /** @return array<string, mixed>|null */
    public function publishedMetadata(
        int $estimateId,
        int $organizationId,
        int $projectId,
    ): ?array;
}
