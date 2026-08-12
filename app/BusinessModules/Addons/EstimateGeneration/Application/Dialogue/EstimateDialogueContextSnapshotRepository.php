<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue;

interface EstimateDialogueContextSnapshotRepository
{
    public function capture(int $organizationId, int $projectId, int $sessionId): EstimateDialogueContextSnapshot;
}
