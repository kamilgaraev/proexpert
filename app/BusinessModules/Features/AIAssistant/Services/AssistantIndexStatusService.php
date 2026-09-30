<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\AIAssistant\Models\RagIndexRun;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentCoverageService;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagCoverageService;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexingCoordinator;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final class AssistantIndexStatusService
{
    public function __construct(
        private readonly RagCoverageService $coverage,
        private readonly AssistantDocumentCoverageService $documents,
        private readonly RagIndexingCoordinator $coordinator,
        private readonly AssistantDataAccessPolicy $access,
        private readonly AuthorizationService $authorization,
    ) {}

    public function status(int $organizationId, User $actor): array
    {
        $coverage = $this->coverage->coverageForActor($organizationId, $actor);

        return array_merge($coverage, $this->documents->coverage($organizationId, $actor), [
            'enabled' => (bool) config('ai-assistant.rag.enabled', true),
            'can_reindex' => $this->canReindex($organizationId, $actor),
        ]);
    }

    public function reindex(int $organizationId, User $actor, array $input): RagIndexRun
    {
        if (!$this->canReindex($organizationId, $actor)) {
            throw new AuthorizationException;
        }
        $projectId = isset($input['project_id']) ? (int) $input['project_id'] : null;
        if ($projectId !== null && !$this->access->canReadEntity($actor, $organizationId, 'project', $projectId)) {
            throw new AuthorizationException;
        }

        return $this->coordinator->queueOrganization(
            $organizationId,
            $projectId,
            isset($input['source_type']) ? trim((string) $input['source_type']) : null,
            RagIndexRun::MODE_MANUAL,
        );
    }

    private function canReindex(int $organizationId, User $actor): bool
    {
        return $this->authorization->canCurrent($actor, 'admin.ai_assistant.rag.manage', ['organization_id' => $organizationId]);
    }
}
