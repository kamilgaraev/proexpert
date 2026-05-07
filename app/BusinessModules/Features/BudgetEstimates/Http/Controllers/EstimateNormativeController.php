<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Http\Controllers;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateNorm;
use App\BusinessModules\Features\BudgetEstimates\Http\Requests\SearchEstimateNormativesRequest;
use App\BusinessModules\Features\BudgetEstimates\Http\Requests\StoreEstimateItemsFromNormativesRequest;
use App\BusinessModules\Features\BudgetEstimates\Services\Normative\EstimateNormativeCatalogService;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\Estimate\EstimateItemResource;
use App\Http\Responses\AdminResponse;
use App\Models\Estimate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;

use function trans_message;

class EstimateNormativeController extends Controller
{
    public function __construct(
        protected EstimateNormativeCatalogService $catalogService,
    ) {}

    public function search(SearchEstimateNormativesRequest $request, int $project, int $estimate): JsonResponse
    {
        try {
            $estimateModel = $this->resolveEstimate($request, $estimate);
            $this->authorize('view', $estimateModel);

            $normatives = $this->catalogService->search($request->validated());

            return AdminResponse::paginated(
                $normatives->items(),
                [
                    'current_page' => $normatives->currentPage(),
                    'per_page' => $normatives->perPage(),
                    'total' => $normatives->total(),
                    'last_page' => $normatives->lastPage(),
                ],
                null,
                Response::HTTP_OK
            );
        } catch (\Throwable $e) {
            if ($e instanceof AuthorizationException) {
                throw $e;
            }

            Log::error('estimate_normatives.search_failed', [
                'estimate_id' => $estimate,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('estimate.operation_failed'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function show(SearchEstimateNormativesRequest $request, int $project, int $estimate, EstimateNorm $norm): JsonResponse
    {
        try {
            $estimateModel = $this->resolveEstimate($request, $estimate);
            $this->authorize('view', $estimateModel);

            return AdminResponse::success($this->catalogService->detail($norm));
        } catch (\Throwable $e) {
            if ($e instanceof AuthorizationException) {
                throw $e;
            }

            Log::error('estimate_normatives.show_failed', [
                'estimate_id' => $estimate,
                'norm_id' => $norm->id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('estimate.operation_failed'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function storeItems(StoreEstimateItemsFromNormativesRequest $request, int $project, int $estimate): JsonResponse
    {
        try {
            $estimateModel = $this->resolveEstimate($request, $estimate);
            $this->authorize('update', $estimateModel);

            $items = $this->catalogService->addItemsFromNormatives($estimateModel, $request->validated()['items']);

            return AdminResponse::success(
                EstimateItemResource::collection($items),
                trans_message('estimate.items_added'),
                Response::HTTP_CREATED
            );
        } catch (\Throwable $e) {
            if ($e instanceof AuthorizationException) {
                throw $e;
            }

            Log::error('estimate_normatives.store_items_failed', [
                'estimate_id' => $estimate,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('estimate.operation_failed'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function resolveEstimate($request, int $estimate): Estimate
    {
        $organizationId = $request->attributes->get('current_organization_id') ?? $request->user()?->current_organization_id;

        return Estimate::query()
            ->where('id', $estimate)
            ->where('organization_id', (int) $organizationId)
            ->firstOrFail();
    }
}
