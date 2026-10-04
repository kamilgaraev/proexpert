<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Retention;

final class RegionalPriceRetentionPolicy
{
    /**
     * @param iterable<array<string, mixed>> $versions
     * @return list<int>
     */
    public function candidates(iterable $versions, int $quarters, string $graceCutoff): array
    {
        $versions = is_array($versions) ? $versions : iterator_to_array($versions);
        $published = [];
        foreach ($versions as $version) {
            if ($version['source'] !== 'fgis_labor_prices' || ! in_array($version['status'], ['active', 'superseded', 'rolled_back'], true)) {
                continue;
            }
            $context = implode(':', [$version['source'], $version['region_id'], $version['price_zone_id']]);
            $period = (int) $version['year'] * 4 + (int) $version['quarter'];
            $current = $published[$context][$period] ?? null;
            if ($current === null || [$version['activated_at'] ?? '', (int) $version['id']] > [$current['activated_at'] ?? '', (int) $current['id']]) {
                $published[$context][$period] = $version;
            }
        }
        $retained = [];
        foreach ($published as $periods) {
            krsort($periods);
            foreach (array_slice($periods, 0, max(4, $quarters), true) as $version) {
                $retained[(int) $version['id']] = true;
            }
        }
        $candidates = [];
        foreach ($versions as $version) {
            $id = (int) $version['id'];
            if ($version['source'] !== 'fgis_labor_prices' || $version['status'] === 'active' || isset($retained[$id])) {
                continue;
            }
            if (! in_array($version['status'], ['superseded', 'rolled_back', 'failed'], true)
                || ! is_string($version['updated_at'] ?? null) || $version['updated_at'] >= $graceCutoff) {
                continue;
            }
            if ($version['status'] !== 'failed'
                && ((int) ($version['year'] ?? 0) < 1900 || ! in_array((int) ($version['quarter'] ?? 0), [1, 2, 3, 4], true)
                    || empty($version['activated_at']))) {
                continue;
            }
            $candidates[] = $id;
        }
        sort($candidates);
        return $candidates;
    }
}
