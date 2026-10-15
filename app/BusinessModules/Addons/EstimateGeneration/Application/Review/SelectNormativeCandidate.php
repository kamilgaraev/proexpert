<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Review;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationActionAuthorization;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationMutationPolicy;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeCandidateSelectionService;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class SelectNormativeCandidate
{
    public function __construct(
        private EstimateGenerationMutationPolicy $policy,
        private EstimateGenerationActionAuthorization $authorization,
        private NormativeCandidateSelectionService $selection,
    ) {}

    public function handle(
        int $sessionId,
        int $organizationId,
        int $projectId,
        User $actor,
        int $expectedVersion,
        string $workItemKey,
        int $normId,
        bool $allowCatalogSelection,
    ): EstimateGenerationSession {
        return DB::transaction(function () use ($sessionId, $organizationId, $projectId, $actor, $expectedVersion, $workItemKey, $normId, $allowCatalogSelection): EstimateGenerationSession {
            $session = EstimateGenerationSession::query()
                ->whereKey($sessionId)
                ->where('organization_id', $organizationId)
                ->where('project_id', $projectId)
                ->lockForUpdate()
                ->firstOrFail();
            $this->authorization->authorize($actor, $session, 'estimate_generation.select_normative');
            $this->policy->review($session, $expectedVersion);
            $this->selection->select($session, $workItemKey, $normId, $allowCatalogSelection);

            return $session;
        });
    }
}
