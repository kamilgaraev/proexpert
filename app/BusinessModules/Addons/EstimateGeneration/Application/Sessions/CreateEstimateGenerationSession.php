<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\SessionStateStore;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CreateEstimateGenerationSession
{
    public function __construct(
        private SessionStateStore $stateStore,
        private InitialProjectFactsWriter $facts,
        private EstimateGenerationActionAuthorization $authorization,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function handle(array $attributes, User $actor, ?string $requestId = null, ?array $requestInput = null): EstimateGenerationSession
    {
        if ($requestId !== null && ! Str::isUuid($requestId)) {
            throw new SessionCreationRequestConflict('session_creation_request_invalid');
        }
        unset($attributes['creation_request_id'], $attributes['creation_request_hash']);
        $this->authorization->authorize($actor, new EstimateGenerationSession([
            'organization_id' => $attributes['organization_id'], 'project_id' => $attributes['project_id'], 'user_id' => $actor->id,
        ]), 'estimate_generation.create');

        return DB::transaction(function () use ($attributes, $actor, $requestId, $requestInput): EstimateGenerationSession {
            if ($requestId !== null) {
                Organization::query()->whereKey($attributes['organization_id'])->lockForUpdate()->firstOrFail();
                $existing = $this->replay((int) $attributes['organization_id'], (int) $attributes['project_id'], $actor,
                    $requestId, $requestInput ?? $attributes);
                if ($existing !== null) {
                    return $existing;
                }
                $attributes['creation_request_id'] = $requestId;
                $attributes['creation_request_hash'] = $this->requestHash((int) $attributes['organization_id'],
                    (int) $attributes['project_id'], $actor, $requestInput ?? $attributes);
            }
            $session = $this->stateStore->create([...$attributes, 'user_id' => (int) $actor->id]);
            $this->authorization->authorize($actor, $session, 'estimate_generation.create');
            $this->facts->write($session, $actor);

            return $session;
        }, 3);
    }

    /** A replay precedes current default resolution: changed price pins cannot create another session. */
    public function replay(int $organizationId, int $projectId, User $actor, ?string $requestId, array $requestInput): ?EstimateGenerationSession
    {
        if ($requestId === null) {
            return null;
        }
        if (! Str::isUuid($requestId)) {
            throw new SessionCreationRequestConflict('session_creation_request_invalid');
        }
        $session = EstimateGenerationSession::query()->where('organization_id', $organizationId)->where('user_id', $actor->id)
            ->where('creation_request_id', $requestId)->first();
        if ($session === null) {
            return null;
        }
        $this->authorization->authorize($actor, $session, 'estimate_generation.create');
        if ((int) $session->project_id !== $projectId || ! is_string($session->creation_request_hash)
            || ! hash_equals($session->creation_request_hash, $this->requestHash($organizationId, $projectId, $actor, $requestInput))) {
            throw new SessionCreationRequestConflict('session_creation_request_changed');
        }

        return $session;
    }

    private function requestHash(int $organizationId, int $projectId, User $actor, array $input): string
    {
        unset($input['request_id'], $input['creation_request_id'], $input['creation_request_hash']);

        return hash('sha256', CanonicalPipelineJson::encode(['organization_id' => $organizationId, 'project_id' => $projectId,
            'actor_id' => (int) $actor->id, 'input' => $input]));
    }

    /** @return array{request_id:string,status:string,id:int|null,cost_units:string} */
    public function readCreation(int $organizationId, int $projectId, User $actor, string $requestId): array
    {
        if (! Str::isUuid($requestId)) {
            throw new SessionCreationRequestConflict('session_creation_request_invalid');
        }
        $scope = new EstimateGenerationSession(['organization_id' => $organizationId, 'project_id' => $projectId, 'user_id' => $actor->id]);
        $this->authorization->authorize($actor, $scope, 'estimate_generation.create');
        $session = EstimateGenerationSession::query()->where('organization_id', $organizationId)->where('project_id', $projectId)
            ->where('user_id', $actor->id)->where('creation_request_id', $requestId)->first();

        return ['request_id' => $requestId, 'status' => $session === null ? 'not_found' : 'created',
            'id' => $session === null ? null : (int) $session->id, 'cost_units' => '0'];
    }
}
