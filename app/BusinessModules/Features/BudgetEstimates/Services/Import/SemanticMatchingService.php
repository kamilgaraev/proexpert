<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import;

use App\Models\Material;
use App\Models\NormativeRate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SemanticMatchingService
{
    private const CACHE_TTL      = 3600;
    private const CACHE_PREFIX   = 'semantic_match:';
    private const MIN_SIMILARITY = 0.35;
    private const MAX_RESULTS    = 5;

    public function findSimilarNormatives(string $name, ?string $unit = null): array
    {
        if (empty(trim($name))) {
            return [];
        }

        $cacheKey = self::CACHE_PREFIX . 'norm:' . md5($name . ($unit ?? ''));

        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        $query = DB::table('normative_rates')
            ->selectRaw('id, code, name, measurement_unit, base_price, similarity(name, ?) AS sim', [$name])
            ->whereRaw('similarity(name, ?) > ?', [$name, self::MIN_SIMILARITY])
            ->orderByDesc('sim')
            ->limit(self::MAX_RESULTS);

        if ($unit !== null && trim($unit) !== '') {
            $query->where(function ($q) use ($unit) {
                $q->whereRaw('LOWER(measurement_unit) = LOWER(?)', [$unit])
                  ->orWhereNull('measurement_unit');
            });
        }

        $results = $query->get()->map(fn($row) => [
            'id'           => $row->id,
            'code'         => $row->code,
            'name'         => $row->name,
            'unit'         => $row->measurement_unit,
            'base_price'   => (float)$row->base_price,
            'similarity'   => round((float)$row->sim, 4),
            'source'       => 'normative',
        ])->all();

        if (empty($results)) {
            Log::info("[SemanticMatch] No normative match for: '{$name}'");
        }

        Cache::put($cacheKey, $results, self::CACHE_TTL);

        return $results;
    }

    public function findSimilarMaterials(string $name, int $organizationId, ?string $unit = null): array
    {
        if (empty(trim($name))) {
            return [];
        }

        $cacheKey = self::CACHE_PREFIX . 'mat:' . md5($name . $organizationId . ($unit ?? ''));

        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        $query = DB::table('materials')
            ->selectRaw('id, code, name, similarity(name, ?) AS sim', [$name])
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->whereRaw('similarity(name, ?) > ?', [$name, self::MIN_SIMILARITY])
            ->orderByDesc('sim')
            ->limit(self::MAX_RESULTS);

        $results = $query->get()->map(fn($row) => [
            'id'         => $row->id,
            'code'       => $row->code,
            'name'       => $row->name,
            'similarity' => round((float)$row->sim, 4),
            'source'     => 'material_catalog',
        ])->all();

        Cache::put($cacheKey, $results, self::CACHE_TTL);

        return $results;
    }

    public function getBestNormativeMatch(string $name, ?string $unit = null): ?array
    {
        $results = $this->findSimilarNormatives($name, $unit);

        if (empty($results)) {
            return null;
        }

        $best = $results[0];

        Log::info("[SemanticMatch] Best normative match for '{$name}': '{$best['name']}' (sim={$best['similarity']})");

        return $best;
    }

    public function ensurePgTrgmExtension(): void
    {
        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        } catch (\Throwable $e) {
            Log::warning('[SemanticMatch] Could not create pg_trgm extension: ' . $e->getMessage());
        }
    }
}
