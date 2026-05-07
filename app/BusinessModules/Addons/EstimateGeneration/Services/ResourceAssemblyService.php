<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\EstimateNormativeMatcher;

class ResourceAssemblyService
{
    private const LOW_CONFIDENCE_THRESHOLD = 0.55;

    public function __construct(
        protected EstimateNormativeMatcher $normativeMatcher,
    ) {}

    public function enrich(array $workItems, array $context = []): array
    {
        foreach ($workItems as &$workItem) {
            $match = $this->normativeMatcher->matchWorkItem($workItem, $context);

            if ($match === null) {
                $workItem = $this->markUnmatched($workItem);
                continue;
            }

            $workItem = $this->applyNormativeMatch($workItem, $match);
        }

        return $workItems;
    }

    /**
     * @param array<string, mixed> $workItem
     * @param array<string, mixed> $match
     * @return array<string, mixed>
     */
    private function applyNormativeMatch(array $workItem, array $match): array
    {
        $selected = $match['selected'];
        $version = $match['version'];
        $workQuantity = max((float) ($workItem['quantity'] ?? 1), 1.0);
        $resources = $selected['resources'];

        $workItem['materials'] = $this->mapResources($resources['materials'] ?? [], 'material', $workQuantity, $selected, $version);
        $workItem['labor'] = $this->mapResources($resources['labor'] ?? [], 'labor', $workQuantity, $selected, $version);
        $workItem['machinery'] = $this->mapResources($resources['machinery'] ?? [], 'machinery', $workQuantity, $selected, $version);
        $workItem['other_resources'] = $this->mapResources($resources['other'] ?? [], 'other', $workQuantity, $selected, $version);
        $workItem['normative_rate_code'] = $selected['code'];
        $workItem['normative_dataset'] = $version;
        $workItem['normative_match'] = [
            'status' => 'matched',
            'selected_candidate_key' => $selected['key'],
            'norm_id' => $selected['norm_id'],
            'code' => $selected['code'],
            'name' => $selected['name'],
            'unit' => $selected['unit'],
            'collection' => $selected['collection'],
            'section' => $selected['section'],
            'dataset_version' => $version,
            'score' => $selected['score'],
            'confidence' => $selected['confidence'],
            'match_reasons' => $selected['match_reasons'],
            'warnings' => $selected['warnings'],
            'resources_count' => $this->resourcesCount($resources),
            'priced_resources_count' => $this->pricedResourcesCount($resources),
        ];
        $workItem['normative_candidates'] = array_map(
            fn (array $candidate): array => $this->candidateSummary($candidate),
            $match['candidates']
        );
        $workItem['confidence'] = round(((float) ($workItem['confidence'] ?? 0.5) + (float) $selected['confidence']) / 2, 4);

        $flags = $workItem['validation_flags'] ?? [];

        if ((float) $selected['confidence'] < self::LOW_CONFIDENCE_THRESHOLD) {
            $flags[] = 'normative_match_low_confidence';
        }

        if ($workItem['materials'] === [] && $workItem['labor'] === [] && $workItem['machinery'] === []) {
            $flags[] = 'normative_resources_empty';
        }

        if ($this->pricedResourcesCount($resources) === 0) {
            $flags[] = 'normative_prices_missing';
        }

        $workItem['validation_flags'] = array_values(array_unique($flags));

        return $workItem;
    }

    /**
     * @param array<int, array<string, mixed>> $resources
     * @param array<string, mixed> $selected
     * @param array<string, mixed> $version
     * @return array<int, array<string, mixed>>
     */
    private function mapResources(array $resources, string $targetType, float $workQuantity, array $selected, array $version): array
    {
        return array_map(
            function (array $resource, int $index) use ($targetType, $workQuantity, $selected, $version): array {
                $quantityPerUnit = $resource['quantity'] !== null ? (float) $resource['quantity'] : 0.0;
                $quantity = round($quantityPerUnit * $workQuantity, 6);
                $unitPrice = (float) ($resource['unit_price'] ?? 0);

                return [
                    'key' => 'norm-' . $selected['norm_id'] . '-' . $targetType . '-' . ($index + 1),
                    'name' => $resource['name'] ?? $resource['code'] ?? 'resource',
                    'resource_type' => $targetType,
                    'unit' => $resource['unit'],
                    'quantity' => $quantity,
                    'quantity_per_unit' => $quantityPerUnit,
                    'quantity_basis' => 'normative_resource',
                    'unit_price' => $unitPrice,
                    'total_price' => round($quantity * $unitPrice, 2),
                    'source' => 'fsnb_2022:' . $version['version_key'],
                    'confidence' => $selected['confidence'],
                    'normative_ref' => [
                        'norm_id' => $selected['norm_id'],
                        'norm_code' => $selected['code'],
                        'resource_code' => $resource['code'],
                        'resource_id' => $resource['linked_resource_id'],
                        'price_id' => $resource['price_id'],
                        'price_source' => $resource['price_source'],
                    ],
                ];
            },
            array_values($resources),
            array_keys(array_values($resources))
        );
    }

    /**
     * @param array<string, mixed> $workItem
     * @return array<string, mixed>
     */
    private function markUnmatched(array $workItem): array
    {
        $flags = $workItem['validation_flags'] ?? [];
        $flags[] = 'normative_not_found';

        $workItem['materials'] = [];
        $workItem['labor'] = [];
        $workItem['machinery'] = [];
        $workItem['other_resources'] = [];
        $workItem['normative_match'] = [
            'status' => 'not_found',
        ];
        $workItem['normative_candidates'] = [];
        $workItem['validation_flags'] = array_values(array_unique($flags));

        return $workItem;
    }

    /**
     * @param array<string, mixed> $candidate
     * @return array<string, mixed>
     */
    private function candidateSummary(array $candidate): array
    {
        return [
            'key' => $candidate['key'],
            'norm_id' => $candidate['norm_id'],
            'code' => $candidate['code'],
            'name' => $candidate['name'],
            'unit' => $candidate['unit'],
            'collection' => $candidate['collection'],
            'section' => $candidate['section'],
            'score' => $candidate['score'],
            'confidence' => $candidate['confidence'],
            'match_reasons' => $candidate['match_reasons'],
            'warnings' => $candidate['warnings'],
            'resources_count' => $this->resourcesCount($candidate['resources']),
            'priced_resources_count' => $this->pricedResourcesCount($candidate['resources']),
        ];
    }

    /**
     * @param array<string, mixed> $resources
     */
    private function resourcesCount(array $resources): int
    {
        return count($resources['materials'] ?? [])
            + count($resources['machinery'] ?? [])
            + count($resources['labor'] ?? [])
            + count($resources['other'] ?? []);
    }

    /**
     * @param array<string, mixed> $resources
     */
    private function pricedResourcesCount(array $resources): int
    {
        $count = 0;

        foreach ($resources as $group) {
            foreach ($group as $resource) {
                if (($resource['price_source'] ?? null) !== null) {
                    $count++;
                }
            }
        }

        return $count;
    }
}
