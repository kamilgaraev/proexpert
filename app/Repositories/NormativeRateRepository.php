<?php

namespace App\Repositories;

use App\Models\NormativeRate;
use App\Models\NormativeCollection;
use App\Models\NormativeSection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class NormativeRateRepository
{
    protected const CACHE_TTL = 3600;
    protected const CACHE_TAG = 'normative_rates';

    public function find(int $id): ?NormativeRate
    {
        return Cache::tags([self::CACHE_TAG])->remember(
            "normative_rate.{$id}",
            self::CACHE_TTL,
            fn() => NormativeRate::with(['collection', 'section', 'resources'])->find($id)
        );
    }

    public function findByCode(string $code, ?int $collectionId = null): ?NormativeRate
    {
        $query = NormativeRate::where('code', $code);
        
        if ($collectionId) {
            $query->where('collection_id', $collectionId);
        }
        
        return $query->first();
    }

    public function searchByText(string $searchTerm, array $filters = []): LengthAwarePaginator
    {
        $query = NormativeRate::query();
        
        $query->whereRaw(
            "search_vector @@ plainto_tsquery('russian', ?)",
            [$searchTerm]
        );
        
        $this->applyFilters($query, $filters);
        
        return $query->with(['collection', 'section'])
            ->orderByRaw(
                "ts_rank(search_vector, plainto_tsquery('russian', ?)) DESC",
                [$searchTerm]
            )
            ->paginate($filters['per_page'] ?? 20);
    }

    public function fuzzySearch(string $searchTerm, array $filters = []): LengthAwarePaginator
    {
        $query = NormativeRate::query();
        
        $query->whereRaw(
            "similarity(name, ?) > 0.3 OR similarity(code, ?) > 0.3",
            [$searchTerm, $searchTerm]
        );
        
        $this->applyFilters($query, $filters);
        
        return $query->with(['collection', 'section'])
            ->orderByRaw(
                "GREATEST(similarity(name, ?), similarity(code, ?)) DESC",
                [$searchTerm, $searchTerm]
            )
            ->paginate($filters['per_page'] ?? 20);
    }

    public function getByCollection(int $collectionId, array $filters = []): LengthAwarePaginator
    {
        $query = NormativeRate::where('collection_id', $collectionId);
        
        $this->applyFilters($query, $filters);
        
        return $query->with(['section', 'resources'])
            ->orderBy('code')
            ->paginate($filters['per_page'] ?? 50);
    }

    public function getBySection(int $sectionId, array $filters = []): Collection
    {
        $cacheKey = "normative_rates.section.{$sectionId}." . md5(json_encode($filters));
        
        return Cache::tags([self::CACHE_TAG])->remember(
            $cacheKey,
            self::CACHE_TTL,
            function () use ($sectionId, $filters) {
                $query = NormativeRate::where('section_id', $sectionId);
                
                $this->applyFilters($query, $filters);
                
                return $query->with('resources')->orderBy('code')->get();
            }
        );
    }

    public function getMostUsed(int $limit = 50): Collection
    {
        return Cache::tags([self::CACHE_TAG])->remember(
            "normative_rates.most_used.{$limit}",
            self::CACHE_TTL,
            function () use ($limit) {
                return NormativeRate::select('normative_rates.*')
                    ->leftJoin('estimate_items', 'normative_rates.id', '=', 'estimate_items.normative_rate_id')
                    ->groupBy('normative_rates.id')
                    ->orderByRaw('COUNT(DISTINCT estimate_items.estimate_id) DESC')
                    ->limit($limit)
                    ->get();
            }
        );
    }

    public function getSimilarRates(NormativeRate $rate, int $limit = 10): Collection
    {
        return NormativeRate::where('id', '!=', $rate->id)
            ->where('collection_id', $rate->collection_id)
            ->where(function ($query) use ($rate) {
                $query->where('section_id', $rate->section_id)
                    ->orWhereRaw(
                        "similarity(name, ?) > 0.5",
                        [$rate->name]
                    );
            })
            ->limit($limit)
            ->get();
    }

    public function clearCache(?int $id = null): void
    {
        if ($id) {
            Cache::tags([self::CACHE_TAG])->forget("normative_rate.{$id}");
        } else {
            Cache::tags([self::CACHE_TAG])->flush();
        }
    }

    protected function applyFilters($query, array $filters): void
    {
        if (isset($filters['collection_id'])) {
            $query->where('collection_id', $filters['collection_id']);
        }
        
        if (isset($filters['section_id'])) {
            $query->where('section_id', $filters['section_id']);
        }
        
        if (isset($filters['min_price'])) {
            $query->where('base_price', '>=', $filters['min_price']);
        }
        
        if (isset($filters['max_price'])) {
            $query->where('base_price', '<=', $filters['max_price']);
        }
        
        if (isset($filters['measurement_unit'])) {
            $query->where('measurement_unit', $filters['measurement_unit']);
        }
        
        if (isset($filters['code_prefix'])) {
            $query->where('code', 'LIKE', $filters['code_prefix'] . '%');
        }
    }
}
