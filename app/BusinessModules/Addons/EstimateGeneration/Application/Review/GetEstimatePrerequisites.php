<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Review;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationActionAuthorization;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Models\User;

final readonly class GetEstimatePrerequisites
{
    public function __construct(
        private EstimateGenerationActionAuthorization $authorization,
        private EstimatePrerequisiteBacklog $backlog,
    ) {}

    public function handle(User $actor, int $projectId, int $sessionId): array
    {
        $session = EstimateGenerationSession::query()->where('organization_id', $actor->current_organization_id)
            ->where('project_id', $projectId)->whereKey($sessionId)->firstOrFail();
        $this->authorization->authorize($actor, $session, 'estimate_generation.view');

        return $this->backlog->current($session);
    }
}
