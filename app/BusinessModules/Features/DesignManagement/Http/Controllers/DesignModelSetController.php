<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Http\Controllers;

use App\BusinessModules\Features\DesignManagement\Events\DesignModelSessionTransientEvent;
use App\BusinessModules\Features\DesignManagement\Http\Requests\StoreDesignModelSessionRequest;
use App\BusinessModules\Features\DesignManagement\Http\Requests\StoreDesignModelSessionTransientEventRequest;
use App\BusinessModules\Features\DesignManagement\Http\Requests\ListDesignModelSessionsRequest;
use App\BusinessModules\Features\DesignManagement\Http\Resources\DesignModelSessionListResource;
use App\BusinessModules\Features\DesignManagement\Http\Requests\StoreDesignModelSetRequest;
use App\BusinessModules\Features\DesignManagement\Http\Requests\UpdateDesignModelSetRequest;
use App\BusinessModules\Features\DesignManagement\Http\Resources\DesignModelSessionResource;
use App\BusinessModules\Features\DesignManagement\Http\Resources\DesignModelSetResource;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelSet;
use App\BusinessModules\Features\DesignManagement\Services\DesignModelSessionAccessService;
use App\BusinessModules\Features\DesignManagement\Services\DesignModelSetService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DesignModelSetController extends Controller
{
    public function __construct(
        private readonly DesignModelSetService $service,
        private readonly DesignModelSessionAccessService $sessionAccess,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['project_id' => ['required', 'integer', 'min:1']]);
        if (! $this->sessionAccess->canAccessProject($request->user(), $this->org($request), $request->integer('project_id'))) {
            return AdminResponse::error(trans_message('design_bim.errors.forbidden'), 403);
        }
        $sets = DesignModelSet::query()
            ->where('organization_id', $this->org($request))
            ->when($request->integer('project_id'), fn ($query, $id) => $query->where('project_id', $id))
            ->with('revisions')
            ->latest()
            ->paginate(min(100, max(1, $request->integer('per_page', 25))));

        return AdminResponse::paginated(
            DesignModelSetResource::collection($sets->getCollection()),
            ['current_page' => $sets->currentPage(), 'last_page' => $sets->lastPage(), 'per_page' => $sets->perPage(), 'total' => $sets->total()],
            trans_message('design_bim.messages.sets_loaded')
        );
    }

    public function store(StoreDesignModelSetRequest $request): JsonResponse
    {
        try {
            return AdminResponse::success(
                new DesignModelSetResource($this->service->create($this->org($request), $request->user(), $request->validated())),
                trans_message('design_bim.messages.set_created'),
                201
            );
        } catch (DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        }
    }

    public function show(Request $request, int $setId): JsonResponse
    {
        $set = DesignModelSet::query()
            ->where('organization_id', $this->org($request))
            ->with('revisions')
            ->findOrFail($setId);

        if (! $this->sessionAccess->canAccessProject($request->user(), $this->org($request), (int) $set->project_id)) {
            return AdminResponse::error(trans_message('design_bim.errors.forbidden'), 403);
        }

        return AdminResponse::success(
            new DesignModelSetResource($set),
            trans_message('design_bim.messages.set_loaded')
        );
    }

    public function openRevision(Request $request, int $setId, int $revision): JsonResponse
    {
        try {
            return AdminResponse::success(
                $this->service->openRevision($this->org($request), $request->user(), $setId, $revision),
                trans_message('design_bim.messages.set_loaded')
            );
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 403);
        }
    }

    public function update(UpdateDesignModelSetRequest $request, int $setId): JsonResponse
    {
        try {
            return AdminResponse::success(
                new DesignModelSetResource($this->service->update($this->org($request), $setId, $request->user(), $request->validated())),
                trans_message('design_bim.messages.set_updated')
            );
        } catch (DomainException $e) {
            return AdminResponse::error($e->getMessage(), 409);
        }
    }

    public function storeSession(StoreDesignModelSessionRequest $request): JsonResponse
    {
        return AdminResponse::success(
            new DesignModelSessionResource($this->service->createSession($this->org($request), $request->user(), $request->validated())),
            trans_message('design_bim.messages.session_created'),
            201
        );
    }

    public function sessions(ListDesignModelSessionsRequest $request): JsonResponse
    {
        $data = $request->validated();
        $sessions = $this->service->listSessions(
            $this->org($request),
            $request->user(),
            (int) $data['project_id'],
            min(100, max(1, (int) ($data['per_page'] ?? 25)))
        );

        return AdminResponse::paginated(
            DesignModelSessionListResource::collection($sessions->getCollection()),
            [
                'current_page' => $sessions->currentPage(),
                'last_page' => $sessions->lastPage(),
                'per_page' => $sessions->perPage(),
                'total' => $sessions->total(),
            ],
            trans_message('design_bim.messages.sessions_loaded')
        );
    }

    public function bootstrap(Request $request, int $sessionId): JsonResponse
    {
        try {
            return AdminResponse::success(
                new DesignModelSessionResource($this->service->sessionBootstrap($this->org($request), $request->user(), $sessionId)),
                trans_message('design_bim.messages.session_loaded')
            );
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 403);
        }
    }

    public function transientEvent(StoreDesignModelSessionTransientEventRequest $request, int $sessionId): JsonResponse
    {
        $actor = $request->user();
        if (! $actor || ! $this->sessionAccess->canJoin($actor, $sessionId)) {
            return AdminResponse::error(trans_message('design_bim.errors.session_access_denied'), 403);
        }

        $data = $request->validated();
        $session = $this->service->sessionBootstrap($this->org($request), $actor, $sessionId);
        if ($data['type'] === 'select' && ! in_array((int) $data['payload']['model_version_id'], $session->modelSetRevision->version_ids, true)) {
            return AdminResponse::error(trans_message('design_bim.errors.event_payload_invalid'), 422);
        }

        event(new DesignModelSessionTransientEvent(
            $sessionId,
            $data['type'],
            $data['payload'],
            ['id' => (int) $actor->id, 'name' => (string) $actor->name]
        ));

        return AdminResponse::success(null, trans_message('design_bim.messages.event_relayed'), 202);
    }

    private function org(Request $request): int
    {
        return (int) $request->attributes->get('current_organization_id');
    }
}
