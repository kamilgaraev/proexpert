<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Normatives;

class NormativeCandidatePresenter
{
    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    public function present(array $candidate, ?array $workItem = null): array
    {
        $resources = is_array($candidate['resources'] ?? null) ? $candidate['resources'] : [];
        $pricePreview = $this->pricePreview($candidate, $resources, $workItem);
        $resourcePrices = $this->resourcePrices($resources);

        return [
            'key' => $candidate['key'] ?? null,
            'norm_id' => $candidate['norm_id'] ?? null,
            'catalog_source' => $candidate['catalog_source'] ?? 'estimate_norms',
            'normative_rate_id' => $candidate['normative_rate_id'] ?? null,
            'code' => $candidate['code'] ?? null,
            'name' => $candidate['name'] ?? null,
            'unit' => $candidate['unit'] ?? null,
            'collection' => $this->collection($candidate['collection'] ?? null),
            'section' => $this->section($candidate['section'] ?? null),
            'confidence' => round((float) ($candidate['confidence'] ?? 0), 4),
            'score' => round((float) ($candidate['score'] ?? 0), 4),
            'score_kind' => 'retrieval_score',
            'rerank' => null,
            'resources_count' => $this->resourcesCount($resources),
            'priced_resources_count' => $this->pricedResourcesCount($resources),
            'unpriced_resources_count' => $pricePreview['unpriced_resources_count'],
            'preview_calculable' => $pricePreview['preview_calculable'],
            'unit_price_preview' => $pricePreview['unit_price_preview'],
            'total_cost_preview' => $pricePreview['total_cost_preview'],
            'cost_breakdown_preview' => $pricePreview['cost_breakdown_preview'],
            'price_sources' => $pricePreview['price_sources'],
            'resource_prices' => $resourcePrices,
            'base_catalog_resources_count' => count(array_filter(
                $resourcePrices,
                static fn (array $price): bool => $price['source'] !== 'regional',
            )),
            'match_reasons' => array_values($candidate['match_reasons'] ?? []),
            'warnings' => array_values($candidate['warnings'] ?? []),
            'work_composition' => $this->normalizeComposition($candidate['work_composition'] ?? []),
            'learning_positive_count' => (int) ($candidate['learning_positive_count'] ?? 0),
            'learning_negative_count' => (int) ($candidate['learning_negative_count'] ?? 0),
            'learning_score' => round((float) ($candidate['learning_score'] ?? 0), 2),
            'learning_sources' => $this->learningSources($candidate['learning_sources'] ?? []),
        ];
    }

    /**
     * @return array<int, array{source_type: string|null, decision_status: string|null, normative_code: string|null, is_positive: bool, score: float}>
     */
    private function learningSources(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $sources = [];
        foreach ($value as $source) {
            if (! is_array($source)) {
                continue;
            }

            $sources[] = [
                'source_type' => $this->nullableString($source['source_type'] ?? null),
                'decision_status' => $this->nullableString($source['decision_status'] ?? null),
                'normative_code' => $this->nullableString($source['normative_code'] ?? null),
                'is_positive' => ($source['is_positive'] ?? false) === true,
                'score' => round((float) ($source['score'] ?? 0), 4),
            ];
        }

        return $sources;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return array{code: string|null, name: string|null, norm_type: string|null}|null */
    private function collection(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        return [
            'code' => $this->nullableString($value['code'] ?? null),
            'name' => $this->nullableString($value['name'] ?? null),
            'norm_type' => $this->nullableString($value['norm_type'] ?? null),
        ];
    }

    /** @return array{id: int|null, code: string|null, name: string|null, type: string|null, path: string|null}|null */
    private function section(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        return [
            'id' => isset($value['id']) && is_numeric($value['id']) ? (int) $value['id'] : null,
            'code' => $this->nullableString($value['code'] ?? null),
            'name' => $this->nullableString($value['name'] ?? null),
            'type' => $this->nullableString($value['type'] ?? null),
            'path' => $this->nullableString($value['path'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $resources
     * @param  array<string, mixed>|null  $workItem
     * @return array{
     *     unpriced_resources_count: int,
     *     preview_calculable: bool,
     *     unit_price_preview: float|null,
     *     total_cost_preview: float|null,
     *     cost_breakdown_preview: array{materials: float, machinery: float, labor: float, other: float}|null,
     *     price_sources: array<int, string>
     * }
     */
    private function pricePreview(array $candidate, array $resources, ?array $workItem): array
    {
        $resourceCount = $this->resourcesCount($resources);
        $pricedResourceCount = $this->pricedResourcesCount($resources);
        $quantity = $workItem !== null ? max((float) ($workItem['quantity'] ?? 0), 0.0) : 1.0;
        $quantityFactor = $workItem !== null
            ? NormativeUnitNormalizer::quantityFactor((string) ($workItem['unit'] ?? ''), (string) ($candidate['unit'] ?? ''))
            : 1.0;
        $unitTotals = $this->resourceTotals($resources, $quantityFactor);
        $unitPrice = round(
            $unitTotals['materials'] + $unitTotals['machinery'] + $unitTotals['labor'] + $unitTotals['other'],
            4
        );
        $previewCalculable = $resourceCount > 0 && $pricedResourceCount === $resourceCount && $unitPrice > 0;

        return [
            'unpriced_resources_count' => max($resourceCount - $pricedResourceCount, 0),
            'preview_calculable' => $previewCalculable,
            'unit_price_preview' => $previewCalculable ? $unitPrice : null,
            'total_cost_preview' => $previewCalculable ? round($unitPrice * $quantity, 2) : null,
            'cost_breakdown_preview' => $previewCalculable ? [
                'materials' => round($unitTotals['materials'], 2),
                'machinery' => round($unitTotals['machinery'], 2),
                'labor' => round($unitTotals['labor'], 2),
                'other' => round($unitTotals['other'], 2),
            ] : null,
            'price_sources' => $this->priceSources($resources),
        ];
    }

    /**
     * @param  array<string, mixed>  $resources
     * @return array{materials: float, machinery: float, labor: float, other: float}
     */
    private function resourceTotals(array $resources, float $quantityFactor): array
    {
        $totals = [
            'materials' => 0.0,
            'machinery' => 0.0,
            'labor' => 0.0,
            'other' => 0.0,
        ];

        foreach ($totals as $group => $total) {
            foreach ($resources[$group] ?? [] as $resource) {
                if (! is_array($resource) || ! $this->resourceHasPositivePrice($resource)) {
                    continue;
                }

                $totals[$group] = $total + $this->resourceTotalPrice($resource) * $quantityFactor;
                $total = $totals[$group];
            }
        }

        return $totals;
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    private function resourceTotalPrice(array $resource): float
    {
        if (isset($resource['total_price']) && is_numeric($resource['total_price'])) {
            return (float) $resource['total_price'];
        }

        return (float) ($resource['quantity'] ?? 0) * (float) ($resource['unit_price'] ?? 0);
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    private function resourceHasPositivePrice(array $resource): bool
    {
        return ($resource['price_source'] ?? null) !== null && $this->resourceTotalPrice($resource) > 0;
    }

    /**
     * @param  array<string, mixed>  $resources
     * @return array<int, string>
     */
    private function priceSources(array $resources): array
    {
        $sources = [];

        foreach ($resources as $group) {
            foreach ($group as $resource) {
                if (! is_array($resource)) {
                    continue;
                }

                if (! $this->resourceHasPositivePrice($resource)) {
                    continue;
                }

                $source = trim((string) ($resource['price_source'] ?? ''));
                if ($source !== '') {
                    $sources[] = $source;
                }
            }
        }

        return array_values(array_unique($sources));
    }

    /** @return list<array{resource_code: string, resource_name: string, resource_unit: string|null, price_amount: string, price_unit: string|null, currency: string, source: string, source_version: string|null}> */
    private function resourcePrices(array $resources): array
    {
        $prices = [];
        foreach ($resources as $group) {
            if (! is_array($group)) {
                continue;
            }
            foreach ($group as $resource) {
                if (! is_array($resource) || ! $this->resourceHasPositivePrice($resource)) {
                    continue;
                }
                $code = trim((string) ($resource['code'] ?? ''));
                $name = trim((string) ($resource['name'] ?? ''));
                $amount = trim((string) ($resource['unit_price'] ?? ''));
                $source = match (trim((string) ($resource['price_source'] ?? ''))) {
                    'regional_catalog' => 'regional',
                    'fsbc_base' => 'fsbc_base',
                    'fsnb_base' => 'fsnb_base',
                    'fgis_labor_base' => 'fgis_labor_base',
                    default => null,
                };
                if ($code === '' || $name === '' || ! is_numeric($amount) || (float) $amount <= 0 || $source === null) {
                    continue;
                }
                $prices[] = [
                    'resource_code' => $code,
                    'resource_name' => $name,
                    'resource_unit' => $this->nullableString($resource['unit'] ?? null),
                    'price_amount' => $amount,
                    'price_unit' => $this->nullableString($resource['price_unit'] ?? $resource['unit'] ?? null),
                    'currency' => 'RUB',
                    'source' => $source,
                    'source_version' => $this->nullableString($resource['price_source_version'] ?? null),
                ];
            }
        }

        return $prices;
    }

    /**
     * @param  array<string, mixed>  $resources
     */
    private function resourcesCount(array $resources): int
    {
        return count($resources['materials'] ?? [])
            + count($resources['machinery'] ?? [])
            + count($resources['labor'] ?? [])
            + count($resources['other'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $resources
     */
    private function pricedResourcesCount(array $resources): int
    {
        $count = 0;

        foreach ($resources as $group) {
            foreach ($group as $resource) {
                if (is_array($resource) && $this->resourceHasPositivePrice($resource)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeComposition(mixed $composition): array
    {
        if (! is_array($composition)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $item): string => trim((string) $item), $composition),
            static fn (string $item): bool => $item !== ''
        ));
    }
}
