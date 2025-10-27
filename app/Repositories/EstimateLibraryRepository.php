<?php

namespace App\Repositories;

use App\Models\EstimateLibrary;
use App\Models\EstimateLibraryItem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class EstimateLibraryRepository
{
    protected const CACHE_TTL = 1800;
    protected const CACHE_TAG = 'estimate_libraries';

    public function find(int $id): ?EstimateLibrary
    {
        return Cache::tags([self::CACHE_TAG])->remember(
            "estimate_library.{$id}",
            self::CACHE_TTL,
            fn() => EstimateLibrary::with(['items.positions'])->find($id)
        );
    }

    public function getByOrganization(
        int $organizationId,
        array $filters = []
    ): LengthAwarePaginator {
        $query = EstimateLibrary::where('organization_id', $organizationId)
            ->where('is_active', true);
        
        $this->applyFilters($query, $filters);
        
        return $query->withCount('items')
            ->orderBy('usage_count', 'desc')
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 20);
    }

    public function getAccessible(
        int $organizationId,
        array $filters = []
    ): LengthAwarePaginator {
        $query = EstimateLibrary::where(function ($q) use ($organizationId) {
            $q->where('organization_id', $organizationId)
              ->orWhere('access_level', 'public');
        })->where('is_active', true);
        
        $this->applyFilters($query, $filters);
        
        return $query->withCount('items')
            ->orderBy('usage_count', 'desc')
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 20);
    }

    public function searchByText(string $searchTerm, int $organizationId): Collection
    {
        return EstimateLibrary::whereRaw(
            "search_vector @@ plainto_tsquery('russian', ?)",
            [$searchTerm]
        )->where(function ($q) use ($organizationId) {
            $q->where('organization_id', $organizationId)
              ->orWhere('access_level', 'public');
        })->where('is_active', true)
        ->orderByRaw(
            "ts_rank(search_vector, plainto_tsquery('russian', ?)) DESC",
            [$searchTerm]
        )
        ->limit(50)
        ->get();
    }

    public function getByCategory(string $category, int $organizationId): Collection
    {
        $cacheKey = "estimate_libraries.category.{$category}.{$organizationId}";
        
        return Cache::tags([self::CACHE_TAG])->remember(
            $cacheKey,
            self::CACHE_TTL,
            function () use ($category, $organizationId) {
                return EstimateLibrary::where('category', $category)
                    ->where(function ($q) use ($organizationId) {
                        $q->where('organization_id', $organizationId)
                          ->orWhere('access_level', 'public');
                    })
                    ->where('is_active', true)
                    ->withCount('items')
                    ->orderBy('usage_count', 'desc')
                    ->get();
            }
        );
    }

    public function getMostUsed(int $organizationId, int $limit = 10): Collection
    {
        return Cache::tags([self::CACHE_TAG])->remember(
            "estimate_libraries.most_used.{$organizationId}.{$limit}",
            self::CACHE_TTL,
            function () use ($organizationId, $limit) {
                return EstimateLibrary::where('organization_id', $organizationId)
                    ->where('is_active', true)
                    ->orderBy('usage_count', 'desc')
                    ->limit($limit)
                    ->get();
            }
        );
    }

    public function findItem(int $itemId): ?EstimateLibraryItem
    {
        return Cache::tags([self::CACHE_TAG])->remember(
            "estimate_library_item.{$itemId}",
            self::CACHE_TTL,
            fn() => EstimateLibraryItem::with(['library', 'positions.normativeRate'])->find($itemId)
        );
    }

    public function getItemsByLibrary(int $libraryId): Collection
    {
        return Cache::tags([self::CACHE_TAG])->remember(
            "estimate_library.{$libraryId}.items",
            self::CACHE_TTL,
            fn() => EstimateLibraryItem::where('library_id', $libraryId)
                ->with('positions')
                ->orderBy('usage_count', 'desc')
                ->orderBy('name')
                ->get()
        );
    }

    public function clearCache(?int $id = null): void
    {
        if ($id) {
            Cache::tags([self::CACHE_TAG])->forget("estimate_library.{$id}");
            Cache::tags([self::CACHE_TAG])->forget("estimate_library.{$id}.items");
        } else {
            Cache::tags([self::CACHE_TAG])->flush();
        }
    }

    protected function applyFilters($query, array $filters): void
    {
        if (isset($filters['category'])) {
            $query->where('category', $filters['category']);
        }
        
        if (isset($filters['access_level'])) {
            $query->where('access_level', $filters['access_level']);
        }
        
        if (isset($filters['search'])) {
            $query->whereRaw(
                "search_vector @@ plainto_tsquery('russian', ?)",
                [$filters['search']]
            );
        }
    }
}
