<?php

namespace App\Http\Controllers\Api\Estimates;

use App\Http\Controllers\Controller;
use App\BusinessModules\Features\BudgetEstimates\Services\Library\EstimateLibraryService;
use App\Repositories\EstimateLibraryRepository;
use App\Models\EstimateLibrary;
use App\Models\EstimateLibraryItem;
use App\Models\Estimate;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class EstimateLibraryController extends Controller
{
    public function __construct(
        protected EstimateLibraryService $libraryService,
        protected EstimateLibraryRepository $repository
    ) {}

    public function index(Request $request): JsonResponse
    {
        $organizationId = $request->user()->current_organization_id;
        
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

        return response()->json($libraries);
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

        $organizationId = $request->user()->current_organization_id;
        $userId = $request->user()->id;

        $library = $this->libraryService->createLibrary(
            $organizationId,
            $userId,
            $request->all()
        );

        return response()->json($library, 201);
    }

    public function show(int $id): JsonResponse
    {
        $library = $this->repository->find($id);

        if (!$library) {
            return response()->json(['error' => 'Библиотека не найдена'], 404);
        }

        return response()->json($library);
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

        $library = EstimateLibrary::findOrFail($id);

        $library = $this->libraryService->updateLibrary($library, $request->all());

        return response()->json($library);
    }

    public function destroy(int $id): JsonResponse
    {
        $library = EstimateLibrary::findOrFail($id);

        $this->libraryService->deleteLibrary($library);

        return response()->json(['message' => 'Библиотека удалена'], 200);
    }

    public function items(int $libraryId): JsonResponse
    {
        $items = $this->repository->getItemsByLibrary($libraryId);

        return response()->json($items);
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

        $library = EstimateLibrary::findOrFail($libraryId);

        $item = $this->libraryService->createLibraryItem($library, $request->all());

        return response()->json($item, 201);
    }

    public function applyItem(Request $request, int $itemId): JsonResponse
    {
        $request->validate([
            'estimate_id' => 'required|exists:estimates,id',
            'section_id' => 'nullable|exists:estimate_sections,id',
            'parameters' => 'nullable|array',
        ]);

        $item = EstimateLibraryItem::findOrFail($itemId);
        $estimate = Estimate::findOrFail($request->input('estimate_id'));
        $userId = $request->user()->id;

        try {
            $addedItems = $this->libraryService->applyLibraryItemToEstimate(
                $item,
                $estimate,
                $userId,
                $request->input('parameters', []),
                $request->input('section_id')
            );

            return response()->json([
                'message' => 'Типовое решение применено успешно',
                'added_items_count' => count($addedItems),
                'items' => $addedItems,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function itemStatistics(int $itemId): JsonResponse
    {
        $item = EstimateLibraryItem::findOrFail($itemId);

        $statistics = $this->libraryService->getUsageStatistics($item, 30);

        return response()->json($statistics);
    }

    public function share(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'access_level' => 'required|in:private,organization,public',
        ]);

        $library = EstimateLibrary::findOrFail($id);

        try {
            $library = $this->libraryService->shareLibrary(
                $library,
                $request->input('access_level')
            );

            return response()->json([
                'message' => 'Уровень доступа изменен',
                'library' => $library,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function duplicate(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'name' => 'nullable|string|max:255',
        ]);

        $library = EstimateLibrary::findOrFail($id);
        $organizationId = $request->user()->current_organization_id;
        $userId = $request->user()->id;

        $newLibrary = $this->libraryService->duplicateLibrary(
            $library,
            $organizationId,
            $userId,
            $request->input('name')
        );

        return response()->json($newLibrary, 201);
    }
}

