<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mobile;

use App\BusinessModules\Features\DesignManagement\Exceptions\DesignDrawingException;
use App\BusinessModules\Features\DesignManagement\Http\Requests\ListDesignDrawingStrokesRequest;
use App\BusinessModules\Features\DesignManagement\Http\Requests\StoreDesignDrawingStrokeRequest;
use App\BusinessModules\Features\DesignManagement\Services\DesignDrawingStrokeService;
use App\Http\Controllers\Controller;
use App\Http\Responses\MobileResponse;
use App\Models\User;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MobileDesignDrawingStrokeController extends Controller
{
    public function __construct(private readonly DesignDrawingStrokeService $strokes) {}

    public function index(ListDesignDrawingStrokesRequest $request, int $versionId): JsonResponse
    {
        return $this->respond($request, fn (User $actor, int $organizationId): JsonResponse => MobileResponse::success(
            $this->strokes->changes($actor, $organizationId, $versionId, (int) ($request->validated()['after_revision'] ?? 0))
        ));
    }

    public function store(StoreDesignDrawingStrokeRequest $request, int $versionId): JsonResponse
    {
        return $this->respond($request, fn (User $actor, int $organizationId): JsonResponse => MobileResponse::success(
            $this->strokes->store($actor, $organizationId, $versionId, $request->validated()), null, 201
        ));
    }

    public function destroy(Request $request, int $versionId, string $clientId): JsonResponse
    {
        return $this->respond($request, fn (User $actor, int $organizationId): JsonResponse => MobileResponse::success(
            $this->strokes->delete($actor, $organizationId, $versionId, $clientId)
        ));
    }

    private function respond(Request $request, callable $operation): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return MobileResponse::error(trans_message('errors.unauthenticated'), 401);
        }
        try {
            return $operation($actor, (int) $request->attributes->get('current_organization_id'));
        } catch (DesignDrawingException $exception) {
            return MobileResponse::error(trans_message($exception->messageKey), $exception->getCode());
        } catch (DomainException) {
            return MobileResponse::error(trans_message('design_management.errors.version_not_found'), 404);
        }
    }
}
