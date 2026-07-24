<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\BusinessModules\Features\BudgetEstimates\Http\Requests\EstimateItems\BulkStoreEstimateItemsRequest;
use App\BusinessModules\Features\BudgetEstimates\Http\Requests\EstimateItems\BulkUpdateEstimateItemsRequest;
use App\BusinessModules\Features\BudgetEstimates\Http\Requests\EstimateItems\IndexEstimateItemsRequest;
use App\BusinessModules\Features\BudgetEstimates\Http\Requests\EstimateItems\MoveEstimateItemRequest;
use App\BusinessModules\Features\BudgetEstimates\Http\Requests\EstimateItems\RecalculateEstimateItemNumbersRequest;
use App\BusinessModules\Features\BudgetEstimates\Http\Requests\EstimateItems\ReorderEstimateItemsRequest;
use App\BusinessModules\Features\BudgetEstimates\Http\Requests\EstimateItems\StoreEstimateItemRequest;
use App\BusinessModules\Features\BudgetEstimates\Http\Requests\EstimateItems\UpdateEstimateItemRequest;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateItemNumberingService;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateItemReadService;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateItemWorkflowService;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\Estimate\EstimateItemResource;
use App\Http\Responses\AdminResponse;
use App\Models\Estimate;
use App\Models\EstimateItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

use function trans_message;

class EstimateItemController extends Controller
{
    public function __construct(
        private readonly EstimateItemReadService $readService,
        private readonly EstimateItemWorkflowService $workflowService,
    ) {
    }

    public function index(IndexEstimateItemsRequest $request, $project, int $estimate): JsonResponse
    {
        $estimateModel = $this->findProjectEstimate($request, (int) $project, $estimate);

        $this->authorize('view', $estimateModel);

        $items = $this->readService->paginate(
            $estimateModel,
            $this->normalizePerPage($request->input('per_page', 50))
        );

        return AdminResponse::paginated(
            EstimateItemResource::collection($items),
            [
                'current_page' => $items->currentPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
            null,
            Response::HTTP_OK
        );
    }

    public function store(StoreEstimateItemRequest $request, $project, int $estimate): JsonResponse
    {
        $estimateModel = $this->findProjectEstimate($request, (int) $project, $estimate);

        $this->authorize('update', $estimateModel);

        $item = $this->workflowService->create($estimateModel, $request->validated());

        return AdminResponse::success(
            new EstimateItemResource($item),
            trans_message('estimate.item_added'),
            Response::HTTP_CREATED
        );
    }

    public function bulkStore(BulkStoreEstimateItemsRequest $request, $project, int $estimate): JsonResponse
    {
        $estimateModel = $this->findProjectEstimate($request, (int) $project, $estimate);

        $this->authorize('update', $estimateModel);

        $items = $this->workflowService->bulkCreate($estimateModel, $request->validated('items'));

        return AdminResponse::success(
            EstimateItemResource::collection($items),
            trans_message('estimate.items_added'),
            Response::HTTP_CREATED
        );
    }

    public function show(EstimateItem $item): JsonResponse
    {
        $item->loadMissing('estimate');

        $this->authorize('view', $item->estimate);

        return AdminResponse::success(
            new EstimateItemResource($this->readService->loadDetails($item))
        );
    }

    public function showForProject(Request $request, $project, int $estimate, EstimateItem $item): JsonResponse
    {
        $item = $this->resolveProjectItem($request, (int) $project, $estimate, $item);

        return $this->show($item);
    }

    public function update(UpdateEstimateItemRequest $request, EstimateItem $item): JsonResponse
    {
        $item->loadMissing('estimate');

        $this->authorize('update', $item->estimate);

        $item = $this->workflowService->update($item, $request->validated());

        return AdminResponse::success(
            new EstimateItemResource($item),
            trans_message('estimate.item_updated')
        );
    }

    public function updateForProject(
        UpdateEstimateItemRequest $request,
        $project,
        int $estimate,
        EstimateItem $item
    ): JsonResponse {
        $item = $this->resolveProjectItem($request, (int) $project, $estimate, $item);

        return $this->update($request, $item);
    }

    public function bulkUpdate(BulkUpdateEstimateItemsRequest $request, $project, int $estimate): JsonResponse
    {
        $estimateModel = $this->findProjectEstimate($request, (int) $project, $estimate);

        $this->authorize('update', $estimateModel);

        $itemsData = $request->validated('items');

        try {
            $items = $this->workflowService->bulkUpdate($estimateModel, $itemsData);

            return AdminResponse::success(
                [
                    'updated_count' => count($items),
                    'items' => EstimateItemResource::collection($items),
                ],
                trans_message('estimate.items_updated')
            );
        } catch (Throwable $exception) {
            $this->workflowService->logFailure('bulk_update', $estimateModel, $exception, [
                'user_id' => $request->user()?->id,
                'item_ids' => collect($itemsData)->pluck('id')->all(),
            ]);

            return AdminResponse::error(
                trans_message('estimate.items_update_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    public function destroy(EstimateItem $item): JsonResponse
    {
        $item->loadMissing('estimate');

        $this->authorize('update', $item->estimate);

        $this->workflowService->delete($item);

        return AdminResponse::success(null, trans_message('estimate.item_deleted'));
    }

    public function destroyForProject(Request $request, $project, int $estimate, EstimateItem $item): JsonResponse
    {
        $item = $this->resolveProjectItem($request, (int) $project, $estimate, $item);

        return $this->destroy($item);
    }

    public function move(MoveEstimateItemRequest $request, EstimateItem $item): JsonResponse
    {
        $item->loadMissing('estimate');

        $this->authorize('update', $item->estimate);

        $item = $this->workflowService->moveToSection(
            $item,
            (int) $request->validated('section_id')
        );

        return AdminResponse::success(
            new EstimateItemResource($item),
            trans_message('estimate.item_moved')
        );
    }

    public function moveForProject(MoveEstimateItemRequest $request, $project, int $estimate, EstimateItem $item): JsonResponse
    {
        $item = $this->resolveProjectItem($request, (int) $project, $estimate, $item);

        return $this->move($request, $item);
    }

    public function reorder(ReorderEstimateItemsRequest $request, $project, int $estimate): JsonResponse
    {
        $estimateModel = $this->findProjectEstimate($request, (int) $project, $estimate);

        $this->authorize('update', $estimateModel);

        $validated = $request->validated();
        $numberingMode = $validated['numbering_mode'] ?? EstimateItemNumberingService::NUMBERING_BY_SECTION;

        try {
            $items = $this->workflowService->reorder(
                $estimateModel,
                $validated['items'],
                $numberingMode
            );

            return AdminResponse::success(
                EstimateItemResource::collection($items),
                trans_message('estimate.items_reordered')
            );
        } catch (Throwable $exception) {
            $this->workflowService->logFailure('reorder', $estimateModel, $exception, [
                'user_id' => $request->user()?->id,
            ]);

            return AdminResponse::error(
                trans_message('estimate.items_reorder_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    public function recalculateNumbers(
        RecalculateEstimateItemNumbersRequest $request,
        $project,
        int $estimate
    ): JsonResponse {
        $estimateModel = $this->findProjectEstimate($request, (int) $project, $estimate);

        $this->authorize('update', $estimateModel);

        $numberingMode = $request->validated('numbering_mode')
            ?? EstimateItemNumberingService::NUMBERING_BY_SECTION;

        try {
            $this->workflowService->recalculateNumbers($estimateModel, $numberingMode);

            return AdminResponse::success(
                ['numbering_mode' => $numberingMode],
                trans_message('estimate.item_numbering_recalculated')
            );
        } catch (Throwable $exception) {
            $this->workflowService->logFailure('recalculate_numbers', $estimateModel, $exception, [
                'user_id' => $request->user()?->id,
            ]);

            return AdminResponse::error(
                trans_message('estimate.item_numbering_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    private function findProjectEstimate(Request $request, int $projectId, int $estimateId): Estimate
    {
        return $this->readService->findProjectEstimate(
            (int) $request->attributes->get('current_organization_id'),
            $projectId,
            $estimateId
        );
    }

    private function resolveProjectItem(
        Request $request,
        int $projectId,
        int $estimateId,
        EstimateItem $item
    ): EstimateItem {
        $estimate = $this->findProjectEstimate($request, $projectId, $estimateId);

        return $this->readService->resolveProjectItem($item, $estimate);
    }

    private function normalizePerPage(mixed $value): int
    {
        $perPage = (int) $value;

        if ($perPage <= 0) {
            return 1000;
        }

        return min($perPage, 1000);
    }
}
