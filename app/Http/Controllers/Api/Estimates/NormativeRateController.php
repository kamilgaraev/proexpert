<?php

namespace App\Http\Controllers\Api\Estimates;

use App\Http\Controllers\Controller;
use App\BusinessModules\Features\BudgetEstimates\Services\Normative\NormativeSearchService;
use App\Models\NormativeRate;
use App\Models\NormativeCollection;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class NormativeRateController extends Controller
{
    public function __construct(
        protected NormativeSearchService $searchService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = [
            'collection_id' => $request->input('collection_id'),
            'section_id' => $request->input('section_id'),
            'min_price' => $request->input('min_price'),
            'max_price' => $request->input('max_price'),
            'measurement_unit' => $request->input('measurement_unit'),
            'per_page' => $request->input('per_page', 20),
        ];

        if ($request->has('query')) {
            $rates = $this->searchService->search($request->input('query'), $filters);
        } elseif ($request->has('collection_id')) {
            $rates = $this->searchService->getByCollection($request->input('collection_id'), $filters);
        } else {
            return response()->json(['error' => 'Укажите query или collection_id'], 400);
        }

        return response()->json($rates);
    }

    public function show(int $id): JsonResponse
    {
        $rate = NormativeRate::with(['collection', 'section', 'resources'])->findOrFail($id);

        return response()->json($rate);
    }

    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'query' => 'required|string|min:3',
            'type' => 'nullable|in:text,fuzzy,code,advanced',
        ]);

        $query = $request->input('query');
        $type = $request->input('type', 'text');

        $filters = [
            'collection_id' => $request->input('collection_id'),
            'section_id' => $request->input('section_id'),
            'per_page' => $request->input('per_page', 20),
        ];

        $results = match ($type) {
            'fuzzy' => $this->searchService->fuzzySearch($query, $filters),
            'code' => $this->searchService->searchByCode($query, $filters),
            'advanced' => $this->searchService->advancedSearch(array_merge($filters, ['query' => $query])),
            default => $this->searchService->search($query, $filters),
        };

        return response()->json($results);
    }

    public function collections(Request $request): JsonResponse
    {
        $filters = [];
        
        if ($request->has('base_type_id')) {
            $filters['base_type_id'] = $request->input('base_type_id');
        }

        $collections = $this->searchService->getCollections($filters);

        return response()->json($collections);
    }

    public function sections(Request $request, int $collectionId): JsonResponse
    {
        if ($request->input('hierarchy')) {
            $sections = $this->searchService->getSectionHierarchy($collectionId);
        } else {
            $parentId = $request->input('parent_id');
            $sections = $this->searchService->getSections($collectionId, $parentId);
        }

        return response()->json($sections);
    }

    public function resources(int $id): JsonResponse
    {
        $rate = NormativeRate::with('resources')->findOrFail($id);

        return response()->json($rate->resources);
    }

    public function similar(int $id): JsonResponse
    {
        $rate = NormativeRate::findOrFail($id);
        $similar = $this->searchService->searchSimilar($rate, 10);

        return response()->json($similar);
    }

    public function mostUsed(): JsonResponse
    {
        $rates = $this->searchService->getMostUsed(50);

        return response()->json($rates);
    }
}

