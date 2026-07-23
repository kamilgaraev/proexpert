<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;

interface TargetedPackageDraftWriter
{
    /** @param array<string, mixed> $localEstimate */
    public function syncPackageFromDraft(
        EstimateGenerationSession $session,
        string $packageKey,
        array $localEstimate,
        string $sourceInputVersion,
    ): void;
}
