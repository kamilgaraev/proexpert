<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import;

use App\Models\EstimateItem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AnomalyDetectionService
{
    private const CACHE_TTL         = 3600;
    private const CACHE_PREFIX      = 'anomaly_price_stats:';
    private const Z_SCORE_THRESHOLD = 2.5;
    private const MIN_SAMPLES       = 5;

    public function annotate(array &$rows, int $organizationId): void
    {
        $unitGroups = [];
        foreach ($rows as $idx => $row) {
            if ($row['is_section'] ?? false) {
                continue;
            }
            $unit = mb_strtolower(trim((string)($row['unit'] ?? '')));
            if ($unit !== '') {
                $unitGroups[$unit][] = $idx;
            }
        }

        foreach ($unitGroups as $unit => $indices) {
            $stats = $this->getPriceStats((string)$unit, $organizationId);
            if ($stats === null) {
                continue;
            }

            foreach ($indices as $idx) {
                $price = (float)($rows[$idx]['unit_price'] ?? $rows[$idx]['base_unit_price'] ?? 0);
                if ($price <= 0) {
                    continue;
                }

                $anomaly = $this->detectAnomaly($price, $stats);
                if ($anomaly !== null) {
                    $rows[$idx]['anomaly'] = $anomaly;
                    Log::info("[AnomalyDetection] Anomaly on row {$idx}: price={$price}, unit={$unit}, z={$anomaly['z_score']}");
                }
            }
        }
    }

    public function annotateFromImport(array &$rows, int $organizationId): void
    {
        $this->buildInMemoryStats($rows);
        $this->annotate($rows, $organizationId);
    }

    private function getPriceStats(string $unit, int $organizationId): ?array
    {
        $cacheKey = self::CACHE_PREFIX . md5($unit . $organizationId);

        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        $result = DB::table('estimate_items as ei')
            ->join('estimates as e', 'e.id', '=', 'ei.estimate_id')
            ->leftJoin('measurement_units as m', 'm.id', '=', 'ei.measurement_unit_id')
            ->where('e.organization_id', $organizationId)
            ->whereRaw('LOWER(m.name) = ?', [$unit])
            ->whereNotNull('ei.unit_price')
            ->where('ei.unit_price', '>', 0)
            ->selectRaw('AVG(ei.unit_price) as avg, STDDEV(ei.unit_price) as stddev, COUNT(*) as cnt')
            ->first();

        if (!$result || (int)$result->cnt < self::MIN_SAMPLES) {
            return null;
        }

        $stats = [
            'avg'    => (float)$result->avg,
            'stddev' => (float)$result->stddev,
            'count'  => (int)$result->cnt,
        ];

        Cache::put($cacheKey, $stats, self::CACHE_TTL);

        return $stats;
    }

    private function detectAnomaly(float $price, array $stats): ?array
    {
        if ($stats['stddev'] <= 0) {
            return null;
        }

        $zScore = abs($price - $stats['avg']) / $stats['stddev'];

        if ($zScore < self::Z_SCORE_THRESHOLD) {
            return null;
        }

        $direction = $price > $stats['avg'] ? 'high' : 'low';

        return [
            'is_anomaly' => true,
            'direction'  => $direction,
            'z_score'    => round($zScore, 2),
            'avg_price'  => round($stats['avg'], 2),
            'deviation'  => round(abs($price - $stats['avg']), 2),
            'message'    => "Цена отклоняется от среднего на " . round($zScore, 1) . "σ ({$direction})",
        ];
    }

    private function buildInMemoryStats(array $rows): array
    {
        $byUnit = [];

        foreach ($rows as $row) {
            if ($row['is_section'] ?? false) {
                continue;
            }
            $price = (float)($row['unit_price'] ?? $row['base_unit_price'] ?? 0);
            $unit  = mb_strtolower(trim((string)($row['unit'] ?? '')));
            if ($price > 0 && $unit !== '') {
                $byUnit[$unit][] = $price;
            }
        }

        $stats = [];
        foreach ($byUnit as $unit => $prices) {
            if (count($prices) < 2) {
                continue;
            }
            $avg    = array_sum($prices) / count($prices);
            $sqDiff = array_map(fn($p) => ($p - $avg) ** 2, $prices);
            $stddev = sqrt(array_sum($sqDiff) / count($prices));
            $stats[$unit] = ['avg' => $avg, 'stddev' => $stddev, 'count' => count($prices)];
        }

        return $stats;
    }
}
