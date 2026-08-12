<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;

final readonly class EstimateProposalVersionFence
{
    public function __construct(private EstimateDialogueContextSnapshotRepository $snapshots) {}

    /** @return array<string, mixed> */
    public function capture(EstimateGenerationSession $session): array
    {
        return $this->snapshots->capture(
            (int) $session->organization_id,
            (int) $session->project_id,
            (int) $session->getKey(),
        )->versionFence();
    }
}
