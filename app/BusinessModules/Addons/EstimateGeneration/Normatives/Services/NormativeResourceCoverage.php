<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

final readonly class NormativeResourceCoverage
{
    /** @param array<string, list<array<string, mixed>>> $groups */
    public function complete(int $expectedCount, array $groups): bool
    {
        if ($expectedCount < 1) {
            return false;
        }
        $resources = [];
        foreach ($groups as $group) {
            foreach ($group as $resource) {
                $resources[] = $resource;
            }
        }
        $normResourceIds = array_map(static fn (array $resource): int => (int) ($resource['norm_resource_id'] ?? 0), $resources);
        $priceIds = array_map(static fn (array $resource): int => (int) ($resource['price_id'] ?? 0), $resources);

        return count($resources) === $expectedCount
            && count(array_unique($normResourceIds)) === $expectedCount
            && count(array_filter($normResourceIds, static fn (int $id): bool => $id > 0)) === $expectedCount
            && count(array_filter($priceIds, static fn (int $id): bool => $id > 0)) === $expectedCount;
    }
}
