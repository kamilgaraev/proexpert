<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Normative;

use App\Models\NormativeRate;
use App\Models\NormativeCollection;
use App\Models\NormativeSection;
use App\Repositories\NormativeRateRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class NormativeSearchService
{
    public function __construct(
        protected NormativeRateRepository $repository
    ) {}

    public function search(string $query, array $filters = []): LengthAwarePaginator
    {
        if (strlen($query) < 3) {
            return $this->emptyPaginator();
        }

        if ($this->looksLikeCode($query)) {
            return $this->searchByCode($query, $filters);
        }

        return $this->repository->searchByText($query, $filters);
    }

    public function fuzzySearch(string $query, array $filters = []): LengthAwarePaginator
    {
        if (strlen($query) < 3) {
            return $this->emptyPaginator();
        }

        return $this->repository->fuzzySearch($query, $filters);
    }

    public function searchByCode(string $code, array $filters = []): LengthAwarePaginator
    {
        $query = NormativeRate::query();

        $query->where(function ($q) use ($code) {
            $q->where('code', 'LIKE', $code . '%')
              ->orWhere('code', 'LIKE', '%' . $code . '%');
        });

        if (isset($filters['collection_id'])) {
            $query->where('collection_id', $filters['collection_id']);
        }

        if (isset($filters['section_id'])) {
            $query->where('section_id', $filters['section_id']);
        }

        return $query->with(['collection', 'section'])
            ->orderByRaw("CASE WHEN code LIKE ? THEN 0 ELSE 1 END", [$code . '%'])
            ->orderBy('code')
            ->paginate($filters['per_page'] ?? 20);
    }

    public function searchSimilar(NormativeRate $rate, int $limit = 10): Collection
    {
        return $this->repository->getSimilarRates($rate, $limit);
    }

    public function getByCollection(int $collectionId, array $filters = []): LengthAwarePaginator
    {
        return $this->repository->getByCollection($collectionId, $filters);
    }

    public function getBySection(int $sectionId, array $filters = []): Collection
    {
        return $this->repository->getBySection($sectionId, $filters);
    }

    public function getMostUsed(int $limit = 50): Collection
    {
        return $this->repository->getMostUsed($limit);
    }

    public function advancedSearch(array $criteria): LengthAwarePaginator
    {
        $query = NormativeRate::query();

        if (!empty($criteria['query'])) {
            $query->whereRaw(
                "search_vector @@ plainto_tsquery('russian', ?)",
                [$criteria['query']]
            );
        }

        if (!empty($criteria['code'])) {
            $query->where('code', 'LIKE', '%' . $criteria['code'] . '%');
        }

        if (!empty($criteria['collection_id'])) {
            $query->where('collection_id', $criteria['collection_id']);
        }

        if (!empty($criteria['section_id'])) {
            $query->where('section_id', $criteria['section_id']);
        }

        if (isset($criteria['min_price'])) {
            $query->where('base_price', '>=', $criteria['min_price']);
        }

        if (isset($criteria['max_price'])) {
            $query->where('base_price', '<=', $criteria['max_price']);
        }

        if (!empty($criteria['measurement_unit'])) {
            $query->where('measurement_unit', $criteria['measurement_unit']);
        }

        if (!empty($criteria['has_resources'])) {
            $query->has('resources');
        }

        $orderBy = $criteria['order_by'] ?? 'code';
        $orderDirection = $criteria['order_direction'] ?? 'asc';

        if (!empty($criteria['query'])) {
            $query->orderByRaw(
                "ts_rank(search_vector, plainto_tsquery('russian', ?)) DESC",
                [$criteria['query']]
            );
        } else {
            $query->orderBy($orderBy, $orderDirection);
        }

        return $query->with(['collection', 'section', 'resources'])
            ->paginate($criteria['per_page'] ?? 20);
    }

    public function getCollections(array $filters = []): Collection
    {
        $query = NormativeCollection::query()->where('is_active', true);

        if (isset($filters['base_type_id'])) {
            $query->where('base_type_id', $filters['base_type_id']);
        }

        return $query->withCount('rates')
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get();
    }

    public function getSections(int $collectionId, ?int $parentId = null): Collection
    {
        $query = NormativeSection::where('collection_id', $collectionId);

        if ($parentId) {
            $query->where('parent_id', $parentId);
        } else {
            $query->whereNull('parent_id');
        }

        return $query->withCount('rates')
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get();
    }

    public function getSectionHierarchy(int $collectionId): Collection
    {
        $sections = NormativeSection::where('collection_id', $collectionId)
            ->with('children')
            ->whereNull('parent_id')
            ->orderBy('sort_order')
            ->get();

        return $sections;
    }

    protected function looksLikeCode(string $query): bool
    {
        return preg_match('/^[А-Яа-яA-Za-z0-9\-\.]+$/', $query) && strlen($query) <= 20;
    }

    protected function emptyPaginator(): LengthAwarePaginator
    {
        return new LengthAwarePaginator([], 0, 20);
    }
}

