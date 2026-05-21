<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Estimates;

use App\BusinessModules\Features\BudgetEstimates\Services\Library\EstimateLibraryService;
use App\Http\Controllers\Controller;
use App\Models\Estimate;
use App\Models\EstimateLibrary;
use App\Models\EstimateLibraryItem;
use App\Repositories\EstimateLibraryRepository;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EstimateLibraryController extends Controller
{
    public function __construct(
        protected EstimateLibraryService $libraryService,
        protected EstimateLibraryRepository $repository
    ) {}

    public function index(Request $request): JsonResponse
    {
        $organizationId = $this->currentOrganizationId($request);

        $filters = [
            'category' => $request->input('category'),
            'access_level' => $request->input('access_level'),
            'search' => $request->input('search'),
            'per_page' => $request->input('per_page', 20),
        ];

        $accessible = $request->input('accessible', true);

        $libraries = $accessible
            ? $this->repository->getAccessible($organizationId, $filters)
            : $this->repository->getByOrganization($organizationId, $filters);

        return \App\Http\Responses\AdminResponse::fromPayload($libraries);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'category' => 'nullable|string|max:100',
            'access_level' => 'nullable|in:private,organization,public',
            'tags' => 'nullable|array',
        ]);

        $library = $this->libraryService->createLibrary(
            $this->currentOrganizationId($request),
            (int) $request->user()->id,
            $request->all()
        );

        return \App\Http\Responses\AdminResponse::fromPayload($library, 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $library = $this->accessibleLibraryOrFail($id, $this->currentOrganizationId($request));

        return \App\Http\Responses\AdminResponse::fromPayload($library);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'category' => 'nullable|string|max:100',
            'access_level' => 'nullable|in:private,organization,public',
            'tags' => 'nullable|array',
            'is_active' => 'nullable|boolean',
        ]);

        $library = $this->ownedLibraryOrFail($id, $this->currentOrganizationId($request));
        $library = $this->libraryService->updateLibrary($library, $request->all());

        return \App\Http\Responses\AdminResponse::fromPayload($library);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $library = $this->ownedLibraryOrFail($id, $this->currentOrganizationId($request));

        $this->libraryService->deleteLibrary($library);

        return \App\Http\Responses\AdminResponse::fromPayload(['message' => 'Р‘РёР±Р»РёРѕС‚РµРєР° СѓРґР°Р»РµРЅР°'], 200);
    }

    public function items(Request $request, int $libraryId): JsonResponse
    {
        $items = $this->repository->getItemsByAccessibleLibrary(
            $libraryId,
            $this->currentOrganizationId($request)
        );

        return \App\Http\Responses\AdminResponse::fromPayload($items);
    }

    public function storeItem(Request $request, int $libraryId): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'parameters' => 'nullable|array',
            'calculation_rules' => 'nullable|string',
            'positions' => 'nullable|array',
            'positions.*.normative_rate_id' => 'nullable|exists:normative_rates,id',
            'positions.*.name' => 'required|string',
            'positions.*.quantity_formula' => 'nullable|string',
            'positions.*.default_quantity' => 'nullable|numeric',
        ]);

        $library = $this->ownedLibraryOrFail($libraryId, $this->currentOrganizationId($request));
        $item = $this->libraryService->createLibraryItem($library, $request->all());

        return \App\Http\Responses\AdminResponse::fromPayload($item, 201);
    }

    public function applyItem(Request $request, int $itemId): JsonResponse
    {
        $organizationId = $this->currentOrganizationId($request);
        $estimateId = (int) $request->input('estimate_id');

        $request->validate([
            'estimate_id' => [
                'required',
                'integer',
                Rule::exists('estimates', 'id')->where(
                    static fn (QueryBuilder $query): QueryBuilder => $query
                        ->where('organization_id', $organizationId)
                        ->whereNull('deleted_at')
                ),
            ],
            'section_id' => [
                'nullable',
                'integer',
                Rule::exists('estimate_sections', 'id')->where(
                    static fn (QueryBuilder $query): QueryBuilder => $query->where('estimate_id', $estimateId)
                ),
            ],
            'parameters' => 'nullable|array',
        ]);

        $item = $this->accessibleItemOrFail($itemId, $organizationId);
        $estimate = Estimate::where('organization_id', $organizationId)->findOrFail($estimateId);
        $userId = (int) $request->user()->id;

        try {
            $addedItems = $this->libraryService->applyLibraryItemToEstimate(
                $item,
                $estimate,
                $userId,
                $request->input('parameters', []),
                $request->input('section_id')
            );

            return \App\Http\Responses\AdminResponse::fromPayload([
                'message' => 'РўРёРїРѕРІРѕРµ СЂРµС€РµРЅРёРµ РїСЂРёРјРµРЅРµРЅРѕ СѓСЃРїРµС€РЅРѕ',
                'added_items_count' => count($addedItems),
                'items' => $addedItems,
            ]);
        } catch (\InvalidArgumentException $e) {
            return \App\Http\Responses\AdminResponse::fromPayload(['error' => $e->getMessage()], 400);
        }
    }

    public function itemStatistics(Request $request, int $itemId): JsonResponse
    {
        $item = $this->ownedItemOrFail($itemId, $this->currentOrganizationId($request));
        $statistics = $this->libraryService->getUsageStatistics($item, 30);

        return \App\Http\Responses\AdminResponse::fromPayload($statistics);
    }

    public function share(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'access_level' => 'required|in:private,organization,public',
        ]);

        $library = $this->ownedLibraryOrFail($id, $this->currentOrganizationId($request));

        try {
            $library = $this->libraryService->shareLibrary(
                $library,
                $request->input('access_level')
            );

            return \App\Http\Responses\AdminResponse::fromPayload([
                'message' => 'РЈСЂРѕРІРµРЅСЊ РґРѕСЃС‚СѓРїР° РёР·РјРµРЅРµРЅ',
                'library' => $library,
            ]);
        } catch (\InvalidArgumentException $e) {
            return \App\Http\Responses\AdminResponse::fromPayload(['error' => $e->getMessage()], 400);
        }
    }

    public function duplicate(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'name' => 'nullable|string|max:255',
        ]);

        $organizationId = $this->currentOrganizationId($request);
        $library = $this->accessibleLibraryOrFail($id, $organizationId);

        $newLibrary = $this->libraryService->duplicateLibrary(
            $library,
            $organizationId,
            (int) $request->user()->id,
            $request->input('name')
        );

        return \App\Http\Responses\AdminResponse::fromPayload($newLibrary, 201);
    }

    private function currentOrganizationId(Request $request): int
    {
        $organizationId = $request->user()?->current_organization_id;

        abort_if($organizationId === null, 403);

        return (int) $organizationId;
    }

    private function accessibleLibraryOrFail(int $id, int $organizationId): EstimateLibrary
    {
        $library = $this->repository->findAccessible($id, $organizationId);

        abort_if($library === null, 404);

        return $library;
    }

    private function ownedLibraryOrFail(int $id, int $organizationId): EstimateLibrary
    {
        $library = $this->repository->findOwned($id, $organizationId);

        abort_if($library === null, 404);

        return $library;
    }

    private function accessibleItemOrFail(int $id, int $organizationId): EstimateLibraryItem
    {
        $item = $this->repository->findAccessibleItem($id, $organizationId);

        abort_if($item === null, 404);

        return $item;
    }

    private function ownedItemOrFail(int $id, int $organizationId): EstimateLibraryItem
    {
        $item = $this->repository->findOwnedItem($id, $organizationId);

        abort_if($item === null, 404);

        return $item;
    }
}
