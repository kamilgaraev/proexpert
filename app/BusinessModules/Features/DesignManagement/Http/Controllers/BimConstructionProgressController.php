<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Http\Controllers;

use App\BusinessModules\Features\DesignManagement\Exceptions\ConstructionProgressRevisionConflict;
use App\BusinessModules\Features\DesignManagement\Http\Requests\DeleteBimConstructionProgressGroupRequest;
use App\BusinessModules\Features\DesignManagement\Http\Requests\StoreBimConstructionProgressGroupRequest;
use App\BusinessModules\Features\DesignManagement\Http\Requests\UpdateBimConstructionProgressGroupRequest;
use App\BusinessModules\Features\DesignManagement\Services\BimConstructionProgressService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\User;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BimConstructionProgressController extends Controller
{
    public function __construct(private readonly BimConstructionProgressService $service) {}

    public function index(Request $request, int $versionId): JsonResponse
    {
        return $this->respond(fn (User $actor): array => $this->service->index($actor, $this->organizationId($request), $versionId), 'groups_loaded');
    }

    public function store(StoreBimConstructionProgressGroupRequest $request, int $versionId): JsonResponse
    {
        return $this->respond(fn (User $actor): array => $this->service->create($actor, $this->organizationId($request), $versionId, $request->validated()), 'group_created', 201);
    }

    public function update(UpdateBimConstructionProgressGroupRequest $request, int $versionId, int $groupId): JsonResponse
    {
        return $this->respond(fn (User $actor): array => $this->service->update($actor, $this->organizationId($request), $versionId, $groupId, $request->validated()), 'group_updated');
    }

    public function destroy(DeleteBimConstructionProgressGroupRequest $request, int $versionId, int $groupId): JsonResponse
    {
        return $this->respond(function (User $actor) use ($request, $versionId, $groupId): null {
            $this->service->delete($actor, $this->organizationId($request), $versionId, $groupId, $request->integer('revision'), $request->input('reason'));
            return null;
        }, 'group_deleted');
    }

    private function organizationId(Request $request): int
    {
        return (int) $request->attributes->get('current_organization_id');
    }

    private function respond(callable $callback, string $message, int $status = 200): JsonResponse
    {
        try {
            $actor = request()->user();
            if (! $actor instanceof User) {
                return AdminResponse::error(trans_message('design_construction_progress.errors.forbidden'), 403);
            }
            return AdminResponse::success($callback($actor), trans_message("design_construction_progress.messages.{$message}"), $status);
        } catch (ConstructionProgressRevisionConflict $exception) {
            return AdminResponse::error(trans_message('design_construction_progress.errors.revision_conflict'), 409);
        } catch (DomainException $exception) {
            return AdminResponse::error(trans_message('design_construction_progress.errors.request_failed'), 422);
        }
    }
}
