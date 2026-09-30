<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\AIAssistant\Models\RagIndexRun;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentCoverageService;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagCoverageService;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexingCoordinator;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagStatusBudget;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagStatusBudgetExceeded;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
        if (! $this->access->belongsToOrganization($actor, $organizationId)) {
            throw new AuthorizationException;
        }
        try {
            return (new RagStatusBudget(DB::connection()))->run(function (callable $checkpoint) use ($organizationId, $actor): array {
                $coverage = $this->coverage->coverageForActor($organizationId, $actor, $checkpoint);
                $checkpoint();
                $documents = $this->documents->coverage($organizationId, $actor, $checkpoint);
                $checkpoint();

                return array_merge($coverage, $documents, [
                    'status_available' => true,
                    'enabled' => (bool) config('ai-assistant.rag.enabled', true),
                    'can_reindex' => $this->canReindex($organizationId, $actor),
                ]);
            });
        } catch (RagStatusBudgetExceeded) {
            return $this->unavailableStatus($organizationId, $actor);
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) !== '57014') {
                throw $exception;
            }

            return $this->unavailableStatus($organizationId, $actor);
        }
    }

    private function unavailableStatus(int $organizationId, User $actor): array
    {
        Log::warning('ai_assistant.rag.status_timed_out', ['organization_id' => $organizationId, 'user_id' => $actor->id]);

        return [
            'status_available' => false,
            'enabled' => (bool) config('ai-assistant.rag.enabled', true),
            'ready' => false,
            'coverage_complete' => false,
            'eligible_count_known' => false,
            'source_count' => null,
            'chunk_count' => null,
        ];
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
