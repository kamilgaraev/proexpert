<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Estimates;

use App\BusinessModules\Features\BudgetEstimates\Services\Normative\NormativeSearchService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\NormativeCollection;
use App\Models\NormativeRate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NormativeRateController extends Controller
{
    public function __construct(
        protected NormativeSearchService $searchService
    ) {
    }

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
            $rates = $this->searchService->search((string) $request->input('query'), $filters);
        } elseif ($request->has('collection_id')) {
            $rates = $this->searchService->getByCollection((int) $request->input('collection_id'), $filters);
        } else {
            return AdminResponse::error(trans_message('normative_rates.query_or_collection_required'), Response::HTTP_BAD_REQUEST);
        }

        return $this->paginated($rates, trans_message('normative_rates.loaded'));
    }

    public function show(int $id): JsonResponse
    {
        $rate = NormativeRate::query()
            ->with(['collection', 'section', 'resources'])
            ->find($id);

        if (!$rate) {
            return $this->notFound();
        }

        return AdminResponse::success($rate, trans_message('normative_rates.loaded'));
    }

    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'query' => 'required|string|min:3',
            'type' => 'nullable|in:text,fuzzy,code,advanced',
        ]);

        $query = (string) $request->input('query');
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

        return $this->paginated($results, trans_message('normative_rates.search_loaded'));
    }

    public function collections(Request $request): JsonResponse
    {
        $filters = [];

        if ($request->has('base_type_id')) {
            $filters['base_type_id'] = $request->input('base_type_id');
        }

        $collections = $this->searchService->getCollections($filters);

        return AdminResponse::success($collections, trans_message('normative_rates.collections_loaded'));
    }

    public function sections(Request $request, int $collectionId): JsonResponse
    {
        if (!NormativeCollection::query()->where('id', $collectionId)->where('is_active', true)->exists()) {
            return AdminResponse::error(trans_message('normative_rates.collection_not_found'), Response::HTTP_NOT_FOUND);
        }

        $sections = $request->boolean('hierarchy')
            ? $this->searchService->getSectionHierarchy($collectionId)
            : $this->searchService->getSections($collectionId, $request->input('parent_id') !== null ? (int) $request->input('parent_id') : null);

        return AdminResponse::success($sections, trans_message('normative_rates.sections_loaded'));
    }

    public function resources(int $id): JsonResponse
    {
        $rate = NormativeRate::query()->with('resources')->find($id);

        if (!$rate) {
            return $this->notFound();
        }

        return AdminResponse::success($rate->resources, trans_message('normative_rates.resources_loaded'));
    }

    public function similar(int $id): JsonResponse
    {
        $rate = NormativeRate::query()->find($id);

        if (!$rate) {
            return $this->notFound();
        }

        return AdminResponse::success(
            $this->searchService->searchSimilar($rate, 10),
            trans_message('normative_rates.similar_loaded')
        );
    }

    public function mostUsed(): JsonResponse
    {
        return AdminResponse::success(
            $this->searchService->getMostUsed(50),
            trans_message('normative_rates.loaded')
        );
    }

    private function paginated(LengthAwarePaginator $paginator, string $message): JsonResponse
    {
        return AdminResponse::paginated(
            $paginator->items(),
            [
                'current_page' => $paginator->currentPage(),
                'from' => $paginator->firstItem(),
                'last_page' => $paginator->lastPage(),
                'links' => [],
                'path' => $paginator->path(),
                'per_page' => $paginator->perPage(),
                'to' => $paginator->lastItem(),
                'total' => $paginator->total(),
            ],
            $message,
            Response::HTTP_OK,
            null,
            [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ]
        );
    }

    private function notFound(): JsonResponse
    {
        return AdminResponse::error(trans_message('normative_rates.not_found'), Response::HTTP_NOT_FOUND);
    }
}
