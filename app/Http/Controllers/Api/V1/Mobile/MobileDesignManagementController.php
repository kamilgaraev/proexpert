<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mobile;

use App\BusinessModules\Features\DesignManagement\Http\Requests\StoreDesignModelSessionTransientEventRequest;
use App\BusinessModules\Features\DesignManagement\Http\Requests\StoreDesignModelSessionViewStateRequest;
use App\BusinessModules\Features\DesignManagement\Http\Requests\StoreDesignModelSetRequest;
use App\BusinessModules\Features\DesignManagement\Http\Requests\UpdateDesignModelSetRequest;
use App\Exceptions\BusinessLogicException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Mobile\MobileDesignManagementRequest;
use App\Http\Resources\Mobile\MobileDesignProjectIssueResource;
use App\Http\Responses\MobileResponse;
use App\Models\User;
use App\Services\Mobile\MobileDesignManagementIssueService;
use App\Services\Mobile\MobileDesignManagementService;
use App\Services\Mobile\MobileDesignManagementSessionService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MobileDesignManagementController extends Controller
{
    public function __construct(private readonly MobileDesignManagementService $models,
        private readonly MobileDesignManagementIssueService $issueService, private readonly MobileDesignManagementSessionService $sessions) {}

    public function packages(MobileDesignManagementRequest $request): JsonResponse
    {
        return $this->respond($request, fn (User $actor, int $organizationId): JsonResponse => $this->page($this->models->packages($actor, $organizationId, $request->validated())));
    }

    public function package(MobileDesignManagementRequest $request, int $packageId): JsonResponse
    {
        return $this->respond($request, fn (User $actor, int $organizationId): JsonResponse => MobileResponse::success($this->models->package($actor, $organizationId, $packageId)));
    }

    public function versions(MobileDesignManagementRequest $request): JsonResponse
    {
        return $this->respond($request, fn (User $actor, int $organizationId): JsonResponse => $this->page($this->models->versions($actor, $organizationId, $request->validated())));
    }

    public function viewer(MobileDesignManagementRequest $request, int $versionId): JsonResponse
    {
        return $this->respond($request, fn (User $actor, int $organizationId): JsonResponse => MobileResponse::success($this->models->viewer($actor, $organizationId, $versionId)));
    }

    public function prepare(MobileDesignManagementRequest $request, int $versionId): JsonResponse
    {
        return $this->respond($request, fn (User $actor, int $organizationId): JsonResponse => MobileResponse::success($this->models->prepare($actor, $organizationId, $versionId), null, 202));
    }

    public function offlinePackage(MobileDesignManagementRequest $request, int $versionId): JsonResponse
    {
        return $this->respond($request, fn (User $actor, int $organizationId): JsonResponse => MobileResponse::success($this->models->offlinePackage($actor, $organizationId, $versionId)));
    }

    public function elements(MobileDesignManagementRequest $request, int $versionId): JsonResponse
    {
        return $this->respond($request, fn (User $actor, int $organizationId): JsonResponse => $this->page($this->models->elements($actor, $organizationId, $versionId, $request->validated())));
    }

    public function element(MobileDesignManagementRequest $request, int $versionId, int $expressId): JsonResponse
    {
        return $this->respond($request, fn (User $actor, int $organizationId): JsonResponse => MobileResponse::success($this->models->element($actor, $organizationId, $versionId, $expressId)));
    }

    public function sets(MobileDesignManagementRequest $request): JsonResponse
    {
        return $this->respond($request, fn (User $actor, int $organizationId): JsonResponse => $this->page($this->models->sets($actor, $organizationId, $request->validated())));
    }

    public function showSet(MobileDesignManagementRequest $request, int $setId): JsonResponse
    {
        return $this->respond($request, fn (User $actor, int $organizationId): JsonResponse => MobileResponse::success($this->models->set($actor, $organizationId, $setId)));
    }

    public function storeSet(StoreDesignModelSetRequest $request): JsonResponse
    {
        return $this->respond($request, fn (User $actor, int $organizationId): JsonResponse => MobileResponse::success($this->models->createSet($actor, $organizationId, $request->validated()), null, 201));
    }

    public function updateSet(UpdateDesignModelSetRequest $request, int $setId): JsonResponse
    {
        return $this->respond($request, fn (User $actor, int $organizationId): JsonResponse => MobileResponse::success($this->models->updateSet($actor, $organizationId, $setId, $request->validated())));
    }

    public function deleteSet(MobileDesignManagementRequest $request, int $setId): JsonResponse
    {
        return $this->respond($request, fn (User $actor, int $organizationId): JsonResponse => MobileResponse::success($this->models->deleteSet($actor, $organizationId, $setId, (int) $request->validated('expected_revision'))));
    }

    public function openSet(MobileDesignManagementRequest $request, int $setId, int $revision): JsonResponse
    {
        return $this->respond($request, fn (User $actor, int $organizationId): JsonResponse => MobileResponse::success($this->models->openSet($actor, $organizationId, $setId, $revision)));
    }

    public function issues(MobileDesignManagementRequest $request): JsonResponse
    {
        return $this->respond($request, function (User $actor, int $organizationId) use ($request): JsonResponse {
            $result = $this->issueService->list($actor, $organizationId, $request->validated());

            return MobileResponse::paginated(MobileDesignProjectIssueResource::collection($result['items']), $result['meta']);
        });
    }

    public function showIssue(MobileDesignManagementRequest $request, int $issueId): JsonResponse
    {
        return $this->respond($request, fn (User $actor, int $organizationId): JsonResponse => MobileResponse::success(new MobileDesignProjectIssueResource($this->issueService->find($actor, $organizationId, $issueId))));
    }

    public function storeIssue(MobileDesignManagementRequest $request): JsonResponse
    {
        return $this->respond($request, fn (User $actor, int $organizationId): JsonResponse => $this->mutation($request, $this->issueService->create($actor, $organizationId, $request->validated()), 201));
    }

    public function issueAction(MobileDesignManagementRequest $request, int $issueId, string $action): JsonResponse
    {
        return $this->respond($request, fn (User $actor, int $organizationId): JsonResponse => $this->mutation($request, $this->issueService->act($actor, $organizationId, $issueId, $action, $request->validated())));
    }

    public function snapshot(MobileDesignManagementRequest $request, int $issueId): JsonResponse
    {
        return $this->respond($request, fn (User $actor, int $organizationId): JsonResponse => $this->mutation($request, $this->issueService->attachment($actor, $organizationId, $issueId, 'snapshot', $request->validated())));
    }

    public function photo(MobileDesignManagementRequest $request, int $issueId): JsonResponse
    {
        return $this->respond($request, fn (User $actor, int $organizationId): JsonResponse => $this->mutation($request, $this->issueService->attachment($actor, $organizationId, $issueId, 'photo', $request->validated())));
    }

    public function assignees(MobileDesignManagementRequest $request): JsonResponse
    {
        return $this->respond($request, fn (User $actor, int $organizationId): JsonResponse => $this->page($this->issueService->assignees($actor, $organizationId, $request->validated())));
    }

    public function issueContext(MobileDesignManagementRequest $request, int $issueId): JsonResponse
    {
        return $this->respond($request, fn (User $actor, int $organizationId): JsonResponse => MobileResponse::success($this->issueService->context($actor, $organizationId, $issueId)));
    }

    public function sessions(MobileDesignManagementRequest $request): JsonResponse
    {
        return $this->respond($request, fn (User $actor, int $organizationId): JsonResponse => $this->page($this->sessions->list($actor, $organizationId, $request->validated())));
    }

    public function storeSession(MobileDesignManagementRequest $request): JsonResponse
    {
        return $this->respond($request, fn (User $actor, int $organizationId): JsonResponse => MobileResponse::success($this->sessions->create($actor, $organizationId, $request->validated()), null, 201));
    }

    public function bootstrap(MobileDesignManagementRequest $request, int $sessionId): JsonResponse
    {
        return $this->respond($request, fn (User $actor, int $organizationId): JsonResponse => MobileResponse::success($this->sessions->bootstrap($actor, $organizationId, $sessionId)));
    }

    public function event(StoreDesignModelSessionTransientEventRequest $request, int $sessionId): JsonResponse
    {
        return $this->respond($request, fn (User $actor, int $organizationId): JsonResponse => MobileResponse::success($this->sessions->event($actor, $organizationId, $sessionId, $request->validated())));
    }

    public function participants(MobileDesignManagementRequest $request, int $sessionId): JsonResponse
    {
        return $this->respond($request, fn (User $actor, int $organizationId): JsonResponse => MobileResponse::success($this->sessions->participants($actor, $organizationId, $sessionId)));
    }

    public function storeViewState(StoreDesignModelSessionViewStateRequest $request, int $sessionId): JsonResponse
    {
        return $this->respond($request, fn (User $actor, int $organizationId): JsonResponse => MobileResponse::success($this->sessions->storeViewState($actor, $organizationId, $sessionId, $request->validated())));
    }

    public function viewState(MobileDesignManagementRequest $request, int $sessionId): JsonResponse
    {
        return $this->respond($request, fn (User $actor, int $organizationId): JsonResponse => MobileResponse::success($this->sessions->viewState($actor, $organizationId, $sessionId,
            $request->validated('client_id'), $request->has('revision') ? (int) $request->validated('revision') : null)));
    }

    private function mutation(Request $request, array $result, int $status = 200): JsonResponse
    {
        return MobileResponse::success((new MobileDesignProjectIssueResource($result['issue']))->resolve($request) + ['receipt' => $result['receipt']], null, $status);
    }

    private function page(array $result): JsonResponse
    {
        return MobileResponse::paginated($result['items'], $result['meta']);
    }

    private function respond(Request $request, callable $operation): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return MobileResponse::error(trans_message('errors.unauthenticated'), 401);
        }
        try {
            return $operation($actor, (int) $request->attributes->get('current_organization_id'));
        } catch (BusinessLogicException|DomainException $exception) {
            $status = in_array($exception->getCode(), [403, 404, 409, 422], true) ? $exception->getCode() : 422;
            if ($exception->getMessage() === trans_message('design_bim.errors.session_access_denied')) {
                $status = 403;
            } elseif ($exception->getMessage() === trans_message('design_ifc.errors.element_not_found')) {
                $status = 404;
            }

            return MobileResponse::error($exception->getMessage(), $status);
        }
    }
}
