<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;

interface SessionOperationalSnapshotBuilder
{
    /** @param list<string> $permissions */
    public function handle(EstimateGenerationSession $boundSession, array $permissions): SessionSnapshotData;
}
