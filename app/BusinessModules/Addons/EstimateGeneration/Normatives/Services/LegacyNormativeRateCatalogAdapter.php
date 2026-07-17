<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeUnitNormalizer;
use App\BusinessModules\Features\BudgetEstimates\Services\Normative\NormativeSearchService;
use App\Models\NormativeRate;
use App\Models\NormativeRateResource;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

final class LegacyNormativeRateCatalogAdapter
{
    private const VERSION_KEY = 'normative-rates-v1';

    public function __construct(
        private readonly ?NormativeSearchService $searchService = null,
    ) {}

    /** @return array<string, mixed>|null */
    public function search(array $workItem, array $context = [], int $limit = 10): ?array
    {
        $query = $this->searchText($workItem);
        if (mb_strlen($query) < 3) {
            return null;
        }

        $service = $this->searchService ?? app(NormativeSearchService::class);
        $paginator = $service->search($query, ['per_page' => max(1, min($limit * 2, 40))]);
        if ($paginator->isEmpty()) {
            $paginator = $service->fuzzySearch($query, ['per_page' => max(1, min($limit * 2, 40))]);
        }

        $rates = new EloquentCollection(collect($paginator->items())
            ->filter(static fn (mixed $rate): bool => $rate instanceof NormativeRate)
            ->values()
            ->all());
        $rates->loadMissing(['collection', 'section', 'resources']);

        return $this->matchPayload($rates, $workItem, $context, $limit);
    }

    /** @return array<string, mixed>|null */
    public function find(int $normId, array $workItem, array $context = []): ?array
    {
        $rate = NormativeRate::query()
            ->with(['collection', 'section', 'resources'])
            ->find($normId);

        if (! $rate instanceof NormativeRate) {
            return null;
        }

        return $this->matchPayload(collect([$rate]), $workItem, $context, 1);
    }

    /** @return array<string, mixed> */
    public function present(NormativeRate $rate, array $workItem, array $context = []): array
    {
        $resources = $this->resources($rate);
        $resourceCount = array_sum(array_map('count', $resources));
        $pricedCount = $this->pricedResourcesCount($resources);
        $confidence = $this->confidence($rate, $workItem);
        $unitMatches = NormativeUnitNormalizer::compatible(
            (string) $rate->measurement_unit,
            (string) ($workItem['unit'] ?? '')
        );
        $warnings = [];

        if ($confidence < 0.55) {
            $warnings[] = 'low_normative_confidence';
        }
        if ($resourceCount === 0) {
            $warnings[] = 'norm_without_resources';
        } elseif ($pricedCount === 0) {
            $warnings[] = 'norm_without_resource_prices';
        } elseif ($pricedCount < $resourceCount) {
            $warnings[] = 'norm_with_unpriced_resources';
        }
        if (! $unitMatches) {
            $warnings[] = 'unit_mismatch';
        }

        return [
            'key' => 'normative-rate-'.$rate->id,
            'norm_id' => (int) $rate->id,
            'catalog_source' => 'normative_rates',
            'normative_rate_id' => (int) $rate->id,
            'code' => (string) $rate->code,
            'name' => (string) $rate->name,
            'unit' => (string) $rate->measurement_unit,
            'collection' => [
                'code' => $rate->collection?->code,
                'name' => $rate->collection?->name,
                'norm_type' => mb_strtolower((string) ($rate->collection?->code ?? 'fsnb')),
            ],
            'section' => [
                'id' => $rate->section?->id,
                'code' => $rate->section?->code,
                'name' => $rate->section?->name,
                'type' => null,
                'path' => $rate->section?->path,
            ],
            'work_composition' => $this->composition($rate),
            'score' => round($confidence * 100, 2),
            'confidence' => $confidence,
            'match_reasons' => array_values(array_filter([
                'legacy_catalog',
                $unitMatches ? 'unit' : null,
            ])),
            'warnings' => $warnings,
            'resources' => $resources,
            'learning_positive_count' => 0,
            'learning_negative_count' => 0,
            'learning_score' => 0.0,
            'learning_sources' => [],
        ];
    }

    /** @param Collection<int, NormativeRate> $rates */
    private function matchPayload(Collection $rates, array $workItem, array $context, int $limit): ?array
    {
        $candidates = $rates
            ->map(fn (NormativeRate $rate): array => $this->present($rate, $workItem, $context))
            ->filter(static fn (array $candidate): bool => (float) $candidate['score'] > 0)
            ->sortByDesc('score')
            ->take(max(1, $limit))
            ->values()
            ->all();

        if ($candidates === []) {
            return null;
        }

        return [
            'version' => [
                'source_type' => 'normative_rates',
                'version_key' => self::VERSION_KEY,
            ],
            'price_version' => [
                'source_type' => 'normative_rates',
                'version_key' => self::VERSION_KEY,
            ],
            'price_versions' => [[
                'source_type' => 'normative_rates',
                'version_key' => self::VERSION_KEY,
            ]],
            'rerank' => [
                'status' => 'catalog_adapter',
                'dataset_version' => self::VERSION_KEY,
                'scoring_version' => 'normative-rate-lexical-v1',
                'reranker_version' => null,
                'blocking_issues' => [],
            ],
            'selected' => $candidates[0],
            'candidates' => $candidates,
        ];
    }

    private function searchText(array $workItem): string
    {
        return trim((string) ($workItem['normative_search_text'] ?? $workItem['name'] ?? $workItem['description'] ?? ''));
    }

    private function confidence(NormativeRate $rate, array $workItem): float
    {
        $query = mb_strtolower($this->searchText($workItem));
        $name = mb_strtolower((string) $rate->name);
        $queryTokens = $this->tokens($query);
        $nameTokens = $this->tokens($name);
        $overlap = count(array_intersect($queryTokens, $nameTokens));
        $coverage = $queryTokens === [] ? 0.0 : $overlap / count($queryTokens);
        $confidence = 0.45 + (0.45 * $coverage);

        if ($query !== '' && ($query === $name || str_contains($name, $query) || str_contains($query, $name))) {
            $confidence = max($confidence, 0.9);
        }
        if (NormativeUnitNormalizer::compatible((string) $rate->measurement_unit, (string) ($workItem['unit'] ?? ''))) {
            $confidence += 0.05;
        }

        return round(min(0.95, max(0.35, $confidence)), 4);
    }

    /** @return array<int, string> */
    private function tokens(string $value): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($value)) ?: [];

        return array_values(array_unique(array_filter(
            $parts,
            static fn (string $part): bool => mb_strlen($part) >= 3
        )));
    }

    /** @return array{materials: array<int, array<string, mixed>>, machinery: array<int, array<string, mixed>>, labor: array<int, array<string, mixed>>, other: array<int, array<string, mixed>>} */
    private function resources(NormativeRate $rate): array
    {
        $groups = ['materials' => [], 'machinery' => [], 'labor' => [], 'other' => []];

        foreach ($rate->resources as $resource) {
            if (! $resource instanceof NormativeRateResource) {
                continue;
            }

            $group = match ((string) $resource->resource_type) {
                'material', 'equipment' => 'materials',
                'machinery', 'machine' => 'machinery',
                'labor', 'machine_labor' => 'labor',
                default => 'other',
            };
            $unitPrice = (float) $resource->unit_price;
            $groups[$group][] = $this->resourcePayload(
                $rate,
                $resource,
                (float) $resource->consumption,
                $unitPrice
            );
        }

        if (array_sum(array_map('count', $groups)) === 0 && $this->rateBasePrice($rate) > 0) {
            $groups['other'][] = $this->resourcePayload($rate, null, 1.0, $this->rateBasePrice($rate));
        }

        return $groups;
    }

    /** @return array<string, mixed> */
    private function resourcePayload(
        NormativeRate $rate,
        ?NormativeRateResource $resource,
        float $quantity,
        float $unitPrice
    ): array {
        return [
            'code' => $resource?->code ?? (string) $rate->code,
            'name' => $resource?->name ?? (string) $rate->name,
            'resource_type' => $resource?->resource_type ?? 'other',
            'unit' => $resource?->measurement_unit ?? (string) $rate->measurement_unit,
            'price_unit' => $resource?->measurement_unit ?? (string) $rate->measurement_unit,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'total_price' => round($quantity * $unitPrice, 2),
            'price_source' => $unitPrice > 0 ? 'normative_rate_base' : null,
            'price_id' => null,
            'norm_resource_id' => null,
            'linked_resource_id' => null,
            'embedded_price' => $unitPrice > 0 ? [
                'source_type' => 'normative_rate_base',
                'normative_rate_id' => (int) $rate->id,
                'normative_rate_resource_id' => $resource?->id !== null ? (int) $resource->id : null,
                'base_amount' => $unitPrice,
                'currency' => 'RUB',
                'base_year' => $rate->base_price_year !== null ? (int) $rate->base_price_year : null,
            ] : null,
            'pricing' => null,
        ];
    }

    private function rateBasePrice(NormativeRate $rate): float
    {
        $basePrice = (float) $rate->base_price;
        if ($basePrice > 0) {
            return $basePrice;
        }

        return max(0.0, (float) $rate->materials_cost + (float) $rate->machinery_cost + (float) $rate->labor_cost);
    }

    /** @param array<string, array<int, array<string, mixed>>> $resources */
    private function pricedResourcesCount(array $resources): int
    {
        $count = 0;
        foreach ($resources as $group) {
            foreach ($group as $resource) {
                if (($resource['price_source'] ?? null) !== null && (float) ($resource['unit_price'] ?? 0) > 0) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /** @return array<int, string> */
    private function composition(NormativeRate $rate): array
    {
        $metadata = is_array($rate->metadata) ? $rate->metadata : [];
        $composition = $metadata['work_composition'] ?? $metadata['composition'] ?? [];
        if (is_string($composition)) {
            $composition = preg_split('/\r?\n/u', $composition) ?: [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => trim((string) $item),
            is_array($composition) ? $composition : []
        )));
    }
}
