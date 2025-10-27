<?php

namespace App\Repositories;

use App\Models\PriceIndex;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Eloquent\Collection;

class PriceIndexRepository
{
    protected const CACHE_TTL = 7200;
    protected const CACHE_TAG = 'price_indices';

    public function getForDate(
        string $indexType,
        Carbon $date,
        ?string $regionCode = null
    ): ?PriceIndex {
        $cacheKey = "price_index.{$indexType}.{$date->format('Y-m')}.{$regionCode}";
        
        return Cache::tags([self::CACHE_TAG])->remember(
            $cacheKey,
            self::CACHE_TTL,
            function () use ($indexType, $date, $regionCode) {
                $query = PriceIndex::where('index_type', $indexType)
                    ->where('year', $date->year);
                
                if ($regionCode) {
                    $query->where('region_code', $regionCode);
                }
                
                $query->where(function ($q) use ($date) {
                    $q->where('month', $date->month)
                      ->orWhere(function ($sq) use ($date) {
                          $sq->whereNull('month')
                             ->where('quarter', ceil($date->month / 3));
                      })
                      ->orWhere(function ($sq) {
                          $sq->whereNull('month')
                             ->whereNull('quarter');
                      });
                });
                
                return $query->latest()->first();
            }
        );
    }

    public function getLatest(
        string $indexType,
        ?string $regionCode = null
    ): ?PriceIndex {
        $cacheKey = "price_index.latest.{$indexType}.{$regionCode}";
        
        return Cache::tags([self::CACHE_TAG])->remember(
            $cacheKey,
            self::CACHE_TTL,
            function () use ($indexType, $regionCode) {
                $query = PriceIndex::where('index_type', $indexType);
                
                if ($regionCode) {
                    $query->where('region_code', $regionCode);
                }
                
                return $query->orderBy('year', 'desc')
                    ->orderBy('quarter', 'desc')
                    ->orderBy('month', 'desc')
                    ->first();
            }
        );
    }

    public function getHistory(
        string $indexType,
        Carbon $startDate,
        Carbon $endDate,
        ?string $regionCode = null
    ): Collection {
        $query = PriceIndex::where('index_type', $indexType)
            ->where('year', '>=', $startDate->year)
            ->where('year', '<=', $endDate->year);
        
        if ($regionCode) {
            $query->where('region_code', $regionCode);
        }
        
        return $query->orderBy('year')
            ->orderBy('quarter')
            ->orderBy('month')
            ->get();
    }

    public function getAllForPeriod(
        int $year,
        ?int $quarter = null,
        ?string $regionCode = null
    ): Collection {
        $query = PriceIndex::where('year', $year);
        
        if ($quarter) {
            $query->where('quarter', $quarter);
        }
        
        if ($regionCode) {
            $query->where('region_code', $regionCode);
        }
        
        return $query->orderBy('index_type')->get();
    }

    public function getByRegion(string $regionCode): Collection
    {
        return Cache::tags([self::CACHE_TAG])->remember(
            "price_indices.region.{$regionCode}",
            self::CACHE_TTL,
            fn() => PriceIndex::where('region_code', $regionCode)
                ->latest()
                ->get()
        );
    }

    public function createOrUpdate(array $data): PriceIndex
    {
        $index = PriceIndex::updateOrCreate(
            [
                'index_type' => $data['index_type'],
                'region_code' => $data['region_code'] ?? null,
                'year' => $data['year'],
                'quarter' => $data['quarter'] ?? null,
                'month' => $data['month'] ?? null,
            ],
            $data
        );
        
        $this->clearCache();
        
        return $index;
    }

    public function bulkInsert(array $indices): int
    {
        $count = 0;
        
        foreach ($indices as $indexData) {
            $this->createOrUpdate($indexData);
            $count++;
        }
        
        $this->clearCache();
        
        return $count;
    }

    public function clearCache(): void
    {
        Cache::tags([self::CACHE_TAG])->flush();
    }
}
