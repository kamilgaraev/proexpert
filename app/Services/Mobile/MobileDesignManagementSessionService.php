<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\BusinessModules\Features\DesignManagement\Http\Resources\DesignModelSessionListResource;
use App\BusinessModules\Features\DesignManagement\Http\Resources\DesignModelSessionResource;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelSetRevision;
use App\BusinessModules\Features\DesignManagement\Services\DesignModelSessionAccessService;
use App\BusinessModules\Features\DesignManagement\Services\DesignModelSessionStateService;
use App\BusinessModules\Features\DesignManagement\Services\DesignModelSetService;
use App\Exceptions\BusinessLogicException;
use App\Models\User;

final readonly class MobileDesignManagementSessionService
{
    public function __construct(private MobileDesignManagementAccess $access, private DesignModelSessionAccessService $sessionAccess,
        private DesignModelSetService $sets, private DesignModelSessionStateService $state, private MobileDesignManagementService $models) {}

    public function list(User $actor, int $organizationId, array $filters): array
    {
        $projectId = (int) $filters['project_id'];
        $this->access->project($actor, $organizationId, $projectId);
        $page = $this->sets->listSessions($organizationId, $actor, $projectId, (int) ($filters['per_page'] ?? 20));

        return ['items' => DesignModelSessionListResource::collection($page->getCollection())->resolve(),
            'meta' => $this->models->meta($page) + ['available_actions' => [['key' => 'create', 'label' => trans_message('mobile_design.actions.create_session'), 'enabled' => true]]]];
    }

    public function create(User $actor, int $organizationId, array $payload): array
    {
        $this->access->project($actor, $organizationId, (int) $payload['project_id']);
        if (isset($payload['model_set_revision_id'])) {
            $revision = DesignModelSetRevision::query()->whereHas('modelSet', fn ($query) => $query->where('organization_id', $organizationId)->where('project_id', $payload['project_id']))
                ->with('modelSet')->find($payload['model_set_revision_id']);
            if (! $revision instanceof DesignModelSetRevision) {
                throw new BusinessLogicException(trans_message('errors.resource_not_found'), 404);
            }
            $payload['model_set_id'] = $revision->model_set_id;
            $payload['model_set_revision'] = $revision->revision;
        }
        $this->sets->openRevision($organizationId, $actor, (int) $payload['model_set_id'], (int) $payload['model_set_revision']);
        $session = $this->sets->createSession($organizationId, $actor, $payload);

        return $this->bootstrap($actor, $organizationId, (int) $session->id);
    }

    public function bootstrap(User $actor, int $organizationId, int $sessionId): array
    {
        $this->requireSession($actor, $organizationId, $sessionId);
        $payload = (new DesignModelSessionResource($this->sets->sessionBootstrap($organizationId, $actor, $sessionId)))->resolve();
        $payload['realtime']['auth_endpoint'] = '/api/v1/mobile/broadcasting/auth';

        return $payload;
    }

    public function event(User $actor, int $organizationId, int $sessionId, array $payload): ?array
    {
        $this->requireSession($actor, $organizationId, $sessionId);

        return $this->state->relay($organizationId, $actor, $sessionId, $payload);
    }

    public function participants(User $actor, int $organizationId, int $sessionId): array
    {
        $this->requireSession($actor, $organizationId, $sessionId);

        return $this->state->participants($organizationId, $actor, $sessionId);
    }

    public function storeViewState(User $actor, int $organizationId, int $sessionId, array $payload): ?array
    {
        $this->requireSession($actor, $organizationId, $sessionId);

        return $this->state->storeViewState($organizationId, $actor, $sessionId, $payload);
    }

    public function viewState(User $actor, int $organizationId, int $sessionId, string $clientId, ?int $revision): ?array
    {
        $this->requireSession($actor, $organizationId, $sessionId);

        return $this->state->viewState($organizationId, $actor, $sessionId, $clientId, $revision);
    }

    private function requireSession(User $actor, int $organizationId, int $sessionId): void
    {
        $session = $this->sessionAccess->requireSession($actor, $sessionId, $organizationId);
        $this->access->project($actor, $organizationId, (int) $session->project_id);
    }
}
