<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Planning;

use App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\ProjectUnderstandingCoordinator;
use App\BusinessModules\Addons\EstimateGeneration\Planning\OrganizationPreferenceContext;

final readonly class ProjectPlanningPipeline
{
    public function __construct(
        private ProjectUnderstandingCoordinator $understanding,
        private ProjectPlanningCoordinator $planning,
        private ProjectCompletenessCoordinator $completeness,
    ) {}

    public function refresh(
        int $organizationId,
        int $projectId,
        int $sessionId,
        string $checkpointClaimToken,
        int $logicalAttempt,
    ): ProjectPlanningResult {
        $understanding = $this->understanding->refresh(
            $organizationId,
            $projectId,
            $sessionId,
            $checkpointClaimToken,
            $logicalAttempt,
        );
        $planning = $this->planning->refresh(
            $organizationId,
            $projectId,
            $sessionId,
            new OrganizationPreferenceContext($organizationId, []),
            $understanding,
        );
        if (! $planning->isReadyForCompleteness()) {
            return $planning;
        }

        $this->completeness->refresh($organizationId, $projectId, $sessionId, $planning);

        return $planning;
    }
}
