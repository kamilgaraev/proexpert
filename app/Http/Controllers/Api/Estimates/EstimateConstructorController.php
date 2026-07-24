<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Estimates;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Estimates\Constructor\AddItemsFromCatalogRequest;
use App\Http\Requests\Api\Estimates\Constructor\AddItemsFromNormativesRequest;
use App\Http\Requests\Api\Estimates\Constructor\ApplyCoefficientsRequest;
use App\Http\Requests\Api\Estimates\Constructor\ApplyIndicesRequest;
use App\Http\Requests\Api\Estimates\Constructor\BulkDeleteItemsRequest;
use App\Http\Requests\Api\Estimates\Constructor\BulkUpdateItemsRequest;
use App\Http\Requests\Api\Estimates\Constructor\CopyItemsRequest;
use App\Http\Requests\Api\Estimates\Constructor\MoveItemsToSectionRequest;
use App\Http\Requests\Api\Estimates\Constructor\ReorderItemsRequest;
use App\Http\Responses\AdminResponse;
use App\Services\Estimates\EstimateConstructorService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class EstimateConstructorController extends Controller
{
    public function __construct(
        private readonly EstimateConstructorService $constructorService,
    ) {
    }

    public function addItemsFromNormatives(AddItemsFromNormativesRequest $request, int $estimateId): JsonResponse
    {
        return $this->handleMutation(
            $request,
            $estimateId,
            'addItemsFromNormatives',
            fn (int $organizationId): array => $this->constructorService->addItemsFromNormatives(
                $organizationId,
                $estimateId,
                $request->validated(),
            ),
            trans_message('estimate_constructor.items_added'),
            Response::HTTP_CREATED,
        );
    }

    public function addItemsFromCatalog(AddItemsFromCatalogRequest $request, int $estimateId): JsonResponse
    {
        return $this->handleMutation(
            $request,
            $estimateId,
            'addItemsFromCatalog',
            fn (int $organizationId): array => $this->constructorService->addItemsFromCatalog(
                $organizationId,
                $estimateId,
                $request->validated(),
            ),
            trans_message('estimate_constructor.catalog_items_added'),
            Response::HTTP_CREATED,
        );
    }

    public function bulkUpdate(BulkUpdateItemsRequest $request, int $estimateId): JsonResponse
    {
        return $this->handleMutation(
            $request,
            $estimateId,
            'bulkUpdate',
            fn (int $organizationId): array => $this->constructorService->bulkUpdate(
                $organizationId,
                $estimateId,
                $request->validated(),
            ),
            trans_message('estimate_constructor.items_updated'),
        );
    }

    public function bulkDelete(BulkDeleteItemsRequest $request, int $estimateId): JsonResponse
    {
        return $this->handleMutation(
            $request,
            $estimateId,
            'bulkDelete',
            fn (int $organizationId): array => $this->constructorService->bulkDelete(
                $organizationId,
                $estimateId,
                $request->validated(),
            ),
            trans_message('estimate_constructor.items_deleted'),
        );
    }

    public function reorderItems(ReorderItemsRequest $request, int $estimateId): JsonResponse
    {
        return $this->handleMutation(
            $request,
            $estimateId,
            'reorderItems',
            fn (int $organizationId): array => $this->constructorService->reorderItems(
                $organizationId,
                $estimateId,
                $request->validated(),
            ),
            trans_message('estimate_constructor.items_reordered'),
        );
    }

    public function moveItemsToSection(MoveItemsToSectionRequest $request, int $estimateId): JsonResponse
    {
        return $this->handleMutation(
            $request,
            $estimateId,
            'moveItemsToSection',
            fn (int $organizationId): array => $this->constructorService->moveItemsToSection(
                $organizationId,
                $estimateId,
                $request->validated(),
            ),
            trans_message('estimate_constructor.items_moved'),
        );
    }

    public function copyItems(CopyItemsRequest $request, int $estimateId): JsonResponse
    {
        return $this->handleMutation(
            $request,
            $estimateId,
            'copyItems',
            fn (int $organizationId): array => $this->constructorService->copyItems(
                $organizationId,
                $estimateId,
                $request->validated(),
            ),
            trans_message('estimate_constructor.items_copied'),
            Response::HTTP_CREATED,
        );
    }

    public function applyCoefficientsToItems(ApplyCoefficientsRequest $request, int $estimateId): JsonResponse
    {
        return $this->handleMutation(
            $request,
            $estimateId,
            'applyCoefficientsToItems',
            fn (int $organizationId): array => $this->constructorService->applyCoefficientsToItems(
                $organizationId,
                $estimateId,
                $request->validated(),
            ),
            trans_message('estimate_constructor.coefficients_applied'),
        );
    }

    public function applyIndicesToItems(ApplyIndicesRequest $request, int $estimateId): JsonResponse
    {
        return $this->handleMutation(
            $request,
            $estimateId,
            'applyIndicesToItems',
            fn (int $organizationId): array => $this->constructorService->applyIndicesToItems(
                $organizationId,
                $estimateId,
                $request->validated(),
            ),
            trans_message('estimate_constructor.indices_applied'),
        );
    }

    public function recalculateEstimate(Request $request, int $estimateId): JsonResponse
    {
        return $this->handleMutation(
            $request,
            $estimateId,
            'recalculateEstimate',
            fn (int $organizationId): array => $this->constructorService->recalculateEstimate(
                $organizationId,
                $estimateId,
            ),
            trans_message('estimate_constructor.estimate_recalculated'),
        );
    }

    private function handleMutation(
        Request $request,
        int $estimateId,
        string $operation,
        callable $callback,
        string $message,
        int $statusCode = Response::HTTP_OK,
    ): JsonResponse {
        $organizationId = (int) $request->user()->current_organization_id;

        try {
            return AdminResponse::success(
                $callback($organizationId),
                $message,
                $statusCode,
            );
        } catch (ModelNotFoundException) {
            return AdminResponse::error(
                trans_message('estimate_constructor.not_found'),
                Response::HTTP_NOT_FOUND,
            );
        } catch (Throwable $exception) {
            Log::error('Estimate constructor operation failed', [
                'operation' => $operation,
                'estimate_id' => $estimateId,
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'request_keys' => array_keys($request->all()),
                'exception' => $exception,
            ]);

            return AdminResponse::error(
                trans_message('estimate_constructor.operation_failed'),
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }
    }
}
