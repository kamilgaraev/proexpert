<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Specification\StoreSpecificationRequest;
use App\Http\Requests\Api\V1\Admin\Specification\UpdateSpecificationRequest;
use App\Http\Resources\Api\V1\Admin\Contract\Specification\SpecificationResource;
use App\Http\Responses\AdminResponse;
use App\Services\Contract\SpecificationService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SpecificationController extends Controller
{
    public function __construct(private SpecificationService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->user()?->current_organization_id;
            if ($organizationId === null) {
                return AdminResponse::error(trans_message('contract.organization_context_missing'), 400);
            }

            $projectId = $request->route('project') ?? $request->query('project_id');
            $perPage = max(1, min((int) $request->query('per_page', 15), 100));
            $specifications = $projectId === null
                ? $this->service->paginateForOrganization((int) $organizationId, $perPage)
                : $this->service->paginateByProjectForOrganization(
                    (int) $projectId,
                    (int) $organizationId,
                    $perPage,
                );

            return AdminResponse::paginated(
                SpecificationResource::collection($specifications->getCollection())->resolve($request),
                $this->paginationMeta($specifications),
            );
        } catch (\Throwable $exception) {
            $this->logFailure('index', $exception, $request);

            return AdminResponse::error(trans_message('specification.internal_error_list'), 500);
        }
    }

    public function store(StoreSpecificationRequest $request): JsonResponse
    {
        try {
            $organizationId = $request->user()?->current_organization_id;
            if ($organizationId === null) {
                return AdminResponse::error(trans_message('contract.organization_context_missing'), 400);
            }

            $specification = $this->service->createForOrganization(
                $request->toDto(),
                $request->contractId(),
                (int) $organizationId,
            );
            if ($specification === null) {
                return AdminResponse::error(trans_message('contract.access_denied'), 404);
            }

            return AdminResponse::success(
                new SpecificationResource($specification),
                trans_message('specification.created'),
                201,
            );
        } catch (\Throwable $exception) {
            $this->logFailure('store', $exception, $request);

            return AdminResponse::error(trans_message('specification.internal_error_create'), 500);
        }
    }

    public function show(Request $request, int $specification): JsonResponse
    {
        try {
            $organizationId = $request->user()?->current_organization_id;
            if ($organizationId === null) {
                return AdminResponse::error(trans_message('contract.organization_context_missing'), 400);
            }

            $model = $this->service->getByIdForOrganization($specification, (int) $organizationId);
            if ($model === null) {
                return AdminResponse::error(trans_message('specification.not_found'), 404);
            }

            return AdminResponse::success(new SpecificationResource($model));
        } catch (\Throwable $exception) {
            $this->logFailure('show', $exception, $request, $specification);

            return AdminResponse::error(trans_message('specification.internal_error_get'), 500);
        }
    }

    public function update(
        UpdateSpecificationRequest $request,
        int $specification,
    ): JsonResponse {
        try {
            $organizationId = $request->user()?->current_organization_id;
            if ($organizationId === null) {
                return AdminResponse::error(trans_message('contract.organization_context_missing'), 400);
            }

            $model = $this->service->updateForOrganization(
                $specification,
                (int) $organizationId,
                $request->toPayload(),
            );
            if ($model === null) {
                return AdminResponse::error(trans_message('specification.not_found'), 404);
            }

            return AdminResponse::success(
                new SpecificationResource($model),
                trans_message('specification.updated'),
            );
        } catch (\Throwable $exception) {
            $this->logFailure('update', $exception, $request, $specification);

            return AdminResponse::error(trans_message('specification.internal_error_update'), 500);
        }
    }

    public function destroy(Request $request, int $specification): JsonResponse
    {
        try {
            $organizationId = $request->user()?->current_organization_id;
            if ($organizationId === null) {
                return AdminResponse::error(trans_message('contract.organization_context_missing'), 400);
            }

            if (! $this->service->deleteForOrganization($specification, (int) $organizationId)) {
                return AdminResponse::error(trans_message('specification.not_found'), 404);
            }

            return AdminResponse::success(null, trans_message('specification.deleted'));
        } catch (\Throwable $exception) {
            $this->logFailure('destroy', $exception, $request, $specification);

            return AdminResponse::error(trans_message('specification.internal_error_delete'), 500);
        }
    }

    private function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'from' => $paginator->firstItem(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'to' => $paginator->lastItem(),
            'total' => $paginator->total(),
        ];
    }

    private function logFailure(
        string $action,
        \Throwable $exception,
        Request $request,
        ?int $specificationId = null,
    ): void {
        Log::error('SpecificationController action failed', [
            'action' => $action,
            'specification_id' => $specificationId,
            'organization_id' => $request->user()?->current_organization_id,
            'user_id' => $request->user()?->id,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }
}
