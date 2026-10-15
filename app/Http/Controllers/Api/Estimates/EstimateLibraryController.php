<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Estimates;

use App\BusinessModules\Features\BudgetEstimates\Services\Library\EstimateLibraryService;
use App\Http\Controllers\Controller;
use App\Models\Estimate;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EstimateLibraryController extends Controller
{
    public function __construct(
        protected EstimateLibraryService $libraryService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = [
            'category' => $request->input('category'),
            'access_level' => $request->input('access_level'),
            'search' => $request->input('search'),
            'per_page' => $request->input('per_page', 20),
        ];

        $libraries = $this->libraryService->getLibraries($request->user(), $filters, (bool) $request->input('accessible', true));

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
            $request->user(),
            $request->all()
        );

        return \App\Http\Responses\AdminResponse::fromPayload($library, 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $library = $this->libraryService->getLibrary($id, $request->user());

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

        $library = $this->libraryService->updateLibrary($id, $request->user(), $request->all());

        return \App\Http\Responses\AdminResponse::fromPayload($library);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->libraryService->deleteLibrary($id, $request->user());

        return \App\Http\Responses\AdminResponse::fromPayload(['message' => 'Библиотека удалена'], 200);
    }

    public function items(Request $request, int $libraryId): JsonResponse
    {
        $items = $this->libraryService->getLibraryItems($libraryId, $request->user());

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

        $item = $this->libraryService->createLibraryItem($libraryId, $request->user(), $request->all());

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

        $item = $this->libraryService->getLibraryItem($itemId, $request->user());
        $estimate = Estimate::where('organization_id', $organizationId)->findOrFail($estimateId);

        try {
            $addedItems = $this->libraryService->applyLibraryItemToEstimate(
                $item,
                $estimate,
                $request->user(),
                $request->input('parameters', []),
                $request->input('section_id')
            );

            return \App\Http\Responses\AdminResponse::fromPayload([
                'message' => 'Типовое решение применено успешно',
                'added_items_count' => count($addedItems),
                'items' => $addedItems,
            ]);
        } catch (\InvalidArgumentException $e) {
            return \App\Http\Responses\AdminResponse::fromPayload(['error' => $e->getMessage()], 400);
        }
    }

    public function itemStatistics(Request $request, int $itemId): JsonResponse
    {
        $statistics = $this->libraryService->getUsageStatistics($itemId, $request->user(), 30);

        return \App\Http\Responses\AdminResponse::fromPayload($statistics);
    }

    public function share(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'access_level' => 'required|in:private,organization,public',
        ]);

        try {
            $library = $this->libraryService->shareLibrary(
                $id,
                $request->user(),
                $request->input('access_level')
            );

            return \App\Http\Responses\AdminResponse::fromPayload([
                'message' => 'Уровень доступа изменен',
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

        $newLibrary = $this->libraryService->duplicateLibrary(
            $id,
            $request->user(),
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
}
