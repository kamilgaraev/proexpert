<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums\EstimateImportStatus;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums\EstimateResourceType;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums\EstimateSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateDatasetVersion;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateNorm;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateNormResource;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateResourcePrice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class EstimateNormativeMatcher
{
    private const MAX_QUERY_TOKENS = 10;
    private const MIN_TOKEN_LENGTH = 3;
    private const LOW_CONFIDENCE_THRESHOLD = 0.55;

    /**
     * @return array<string, mixed>|null
     */
    public function matchWorkItem(array $workItem, array $context = [], int $limit = 5): ?array
    {
        $version = $this->latestFsnbVersion();
        $priceVersion = $this->latestFsbcVersion();

        if ($version === null) {
            return null;
        }

        $tokens = $this->tokensForWorkItem($workItem, $context);
        $candidates = $this->candidateNorms($version, $tokens, max($limit * 10, 50));

        if ($candidates->isEmpty()) {
            return null;
        }

        $ranked = $candidates
            ->map(fn (EstimateNorm $norm): array => $this->scoreNorm($norm, $workItem, $context, $tokens, $priceVersion?->id))
            ->filter(static fn (array $candidate): bool => (float) $candidate['score'] > 0)
            ->sortByDesc('score')
            ->values()
            ->take($limit)
            ->all();

        if ($ranked === []) {
            return null;
        }

        return [
            'version' => [
                'source_type' => $version->source_type->value,
                'version_key' => $version->version_key,
            ],
            'price_version' => $priceVersion !== null ? [
                'source_type' => $priceVersion->source_type->value,
                'version_key' => $priceVersion->version_key,
            ] : null,
            'selected' => $ranked[0],
            'candidates' => $ranked,
        ];
    }

    public function latestFsnbVersion(): ?EstimateDatasetVersion
    {
        return EstimateDatasetVersion::query()
            ->where('source_type', EstimateSourceType::FSNB_2022->value)
            ->where('status', EstimateImportStatus::PARSED->value)
            ->latest('id')
            ->first();
    }

    public function latestFsbcVersion(): ?EstimateDatasetVersion
    {
        return EstimateDatasetVersion::query()
            ->where('source_type', EstimateSourceType::FSBC->value)
            ->where('status', EstimateImportStatus::PARSED->value)
            ->latest('id')
            ->first();
    }

    /**
     * @return array<int, string>
     */
    private function tokensForWorkItem(array $workItem, array $context): array
    {
        $parts = [
            $workItem['normative_rate_code'] ?? '',
            $workItem['name'] ?? '',
            $workItem['description'] ?? '',
            $workItem['work_category'] ?? '',
            $context['scope_type'] ?? '',
            $context['section_title'] ?? '',
            $context['local_estimate_title'] ?? '',
            $this->scopeHints((string) ($context['scope_type'] ?? '')),
        ];

        return array_slice($this->tokenize(implode(' ', $parts)), 0, self::MAX_QUERY_TOKENS);
    }

    private function scopeHints(string $scopeType): string
    {
        return match ($scopeType) {
            'foundation' => 'фундамент основание котлован грунт бетон бетонирование арматура армирование гидроизоляция песчаная подготовка',
            'walls' => 'стены кладка перегородки перемычки кирпич блоки армирование кладки',
            'slabs' => 'перекрытия плиты опалубка бетон бетонирование арматура армирование',
            'roof' => 'кровля стропила утепление пароизоляция гидроизоляция покрытие',
            'facade' => 'фасад утепление облицовка штукатурка окраска',
            'engineering' => 'инженерные сети монтаж оборудование вентиляция отопление трубы трубопровод',
            'finishing' => 'отделка штукатурка окраска облицовка шпаклевка',
            'site' => 'благоустройство земляные работы наружные сети планировка',
            default => '',
        };
    }

    /**
     * @param array<int, string> $tokens
     * @return Collection<int, EstimateNorm>
     */
    private function candidateNorms(EstimateDatasetVersion $version, array $tokens, int $limit): Collection
    {
        $query = EstimateNorm::query()
            ->with(['collection', 'section'])
            ->whereHas('collection', static function (Builder $query) use ($version): void {
                $query->where('dataset_version_id', $version->id);
            });

        if ($tokens !== []) {
            $query->where(function (Builder $query) use ($tokens): void {
                foreach ($tokens as $token) {
                    $like = '%' . mb_strtolower($token) . '%';
                    $query->orWhereRaw('LOWER(code) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(COALESCE(section_name, \'\')) LIKE ?', [$like]);
                }
            });
        }

        return $query
            ->orderBy('code')
            ->limit($limit)
            ->get();
    }

    /**
     * @param array<int, string> $tokens
     * @return array<string, mixed>
     */
    private function scoreNorm(EstimateNorm $norm, array $workItem, array $context, array $tokens, ?int $priceVersionId): array
    {
        $name = mb_strtolower($norm->name);
        $section = mb_strtolower((string) ($norm->section_name ?? ''));
        $composition = mb_strtolower(implode(' ', $norm->work_composition ?? []));
        $score = 0.0;
        $reasons = [];

        foreach ($tokens as $token) {
            if ($token === mb_strtolower($norm->code)) {
                $score += 80;
                $reasons[] = 'exact_code';
            }

            if (str_contains($name, $token)) {
                $score += 14;
                $reasons[] = 'name';
            }

            if ($section !== '' && str_contains($section, $token)) {
                $score += 6;
                $reasons[] = 'section';
            }

            if ($composition !== '' && str_contains($composition, $token)) {
                $score += 3;
                $reasons[] = 'composition';
            }
        }

        $unitMatches = ($workItem['unit'] ?? null) !== null && $this->sameUnit((string) $workItem['unit'], (string) $norm->unit);
        if ($unitMatches) {
            $score += 8;
            $reasons[] = 'unit';
        } elseif (($workItem['unit'] ?? null) !== null && (string) $norm->unit !== '') {
            $score -= 6;
            $reasons[] = 'unit_mismatch';
        }

        $scopeType = (string) ($context['scope_type'] ?? '');
        if ($scopeType !== '' && $this->collectionMatchesScope((string) ($norm->collection?->norm_type?->value ?? ''), $scopeType)) {
            $score += 4;
            $reasons[] = 'scope_collection';
        }

        $resources = $this->resourcesForNorm($norm, $priceVersionId);
        $resourceCount = count($resources['materials']) + count($resources['machinery']) + count($resources['labor']) + count($resources['other']);
        $pricedCount = $this->pricedResourcesCount($resources);

        if ($resourceCount > 0) {
            $score += min($resourceCount, 12);
            $reasons[] = 'resources';
        }

        if ($pricedCount > 0) {
            $score += min($pricedCount, 8);
            $reasons[] = 'prices';
        }

        $confidence = min(0.95, max(0.35, round($score / 90, 4)));

        return [
            'key' => 'norm-' . $norm->id,
            'norm_id' => $norm->id,
            'code' => $norm->code,
            'name' => $norm->name,
            'unit' => $norm->unit,
            'collection' => [
                'code' => $norm->collection?->code,
                'name' => $norm->collection?->name,
                'norm_type' => $norm->collection?->norm_type?->value,
            ],
            'section' => [
                'id' => $norm->section?->id,
                'code' => $norm->section?->code,
                'name' => $norm->section?->name,
                'type' => $norm->section?->section_type,
                'path' => $norm->section?->path,
            ],
            'work_composition' => array_slice($norm->work_composition ?? [], 0, 20),
            'score' => round($score, 2),
            'confidence' => $confidence,
            'match_reasons' => array_values(array_unique($reasons)),
            'warnings' => $this->warningsForCandidate($confidence, $resourceCount, $pricedCount, !$unitMatches),
            'resources' => $resources,
        ];
    }

    /**
     * @return array{materials: array<int, array<string, mixed>>, machinery: array<int, array<string, mixed>>, labor: array<int, array<string, mixed>>, other: array<int, array<string, mixed>>}
     */
    private function resourcesForNorm(EstimateNorm $norm, ?int $priceVersionId): array
    {
        $resources = EstimateNormResource::query()
            ->where('estimate_norm_id', $norm->id)
            ->orderBy('id')
            ->limit(120)
            ->get();

        $prices = $priceVersionId !== null
            ? EstimateResourcePrice::query()
                ->where('dataset_version_id', $priceVersionId)
                ->whereIn('resource_code', $resources->pluck('resource_code')->filter()->values()->all())
                ->get()
                ->groupBy('resource_code')
            : collect();

        $grouped = [
            'materials' => [],
            'machinery' => [],
            'labor' => [],
            'other' => [],
        ];

        foreach ($resources as $resource) {
            $type = $resource->resource_type?->value ?? EstimateResourceType::OTHER->value;
            $price = $this->resolvePrice($prices->get($resource->resource_code) ?? collect(), $type, (string) ($resource->unit ?? ''));
            $payload = [
                'code' => $resource->resource_code,
                'name' => $resource->resource_name,
                'resource_type' => $type,
                'unit' => $resource->unit,
                'quantity' => $resource->quantity !== null ? (float) $resource->quantity : null,
                'unit_price' => $price?->base_price !== null ? (float) $price->base_price : 0.0,
                'total_price' => $price?->base_price !== null && $resource->quantity !== null
                    ? round((float) $price->base_price * (float) $resource->quantity, 2)
                    : 0.0,
                'price_source' => $price !== null ? 'fsbc_2022_base' : null,
                'price_id' => $price?->id,
                'linked_resource_id' => $resource->construction_resource_id,
            ];

            if ($type === EstimateResourceType::MATERIAL->value || $type === EstimateResourceType::EQUIPMENT->value) {
                $grouped['materials'][] = $payload;
                continue;
            }

            if ($type === EstimateResourceType::MACHINE->value) {
                $grouped['machinery'][] = $payload;
                continue;
            }

            if ($type === EstimateResourceType::LABOR->value) {
                $grouped['labor'][] = $payload;
                continue;
            }

            $grouped['other'][] = $payload;
        }

        return $grouped;
    }

    /**
     * @param array{materials: array<int, array<string, mixed>>, machinery: array<int, array<string, mixed>>, labor: array<int, array<string, mixed>>, other: array<int, array<string, mixed>>} $resources
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

    /**
     * @return array<int, string>
     */
    private function warningsForCandidate(float $confidence, int $resourceCount, int $pricedCount, bool $unitMismatch): array
    {
        $warnings = [];

        if ($confidence < self::LOW_CONFIDENCE_THRESHOLD) {
            $warnings[] = 'low_normative_confidence';
        }

        if ($resourceCount === 0) {
            $warnings[] = 'norm_without_resources';
        }

        if ($resourceCount > 0 && $pricedCount === 0) {
            $warnings[] = 'norm_without_resource_prices';
        }

        if ($unitMismatch) {
            $warnings[] = 'unit_mismatch';
        }

        return $warnings;
    }

    private function resolvePrice(Collection $prices, string $resourceType, string $unit): ?EstimateResourcePrice
    {
        if ($prices->isEmpty()) {
            return null;
        }

        $preferredType = $resourceType === EstimateResourceType::EQUIPMENT->value
            ? EstimateResourceType::MATERIAL->value
            : $resourceType;

        return $prices->first(function (EstimateResourcePrice $price) use ($preferredType, $unit): bool {
            return ($price->price_type?->value ?? $price->price_type) === $preferredType
                && $this->sameUnit($unit, (string) $price->unit);
        }) ?? $prices->first(function (EstimateResourcePrice $price) use ($preferredType): bool {
            return ($price->price_type?->value ?? $price->price_type) === $preferredType;
        }) ?? $prices->first();
    }

    /**
     * @return array<int, string>
     */
    private function tokenize(string $value): array
    {
        $value = mb_strtolower($value);
        preg_match_all('/[\p{L}\p{N}.-]+/u', $value, $matches);
        $stopWords = [
            'работа',
            'работы',
            'основные',
            'строительные',
            'подготовка',
            'устройство',
            'монтаж',
            'для',
            'при',
            'the',
            'and',
        ];
        $tokens = [];

        foreach ($matches[0] ?? [] as $token) {
            $token = trim($token, '.- ');

            if (mb_strlen($token) < self::MIN_TOKEN_LENGTH || in_array($token, $stopWords, true)) {
                continue;
            }

            $tokens[] = $token;
        }

        return array_values(array_unique($tokens));
    }

    private function sameUnit(string $left, string $right): bool
    {
        $left = $this->normalizeUnit($left);
        $right = $this->normalizeUnit($right);

        return $left !== '' && $right !== '' && $left === $right;
    }

    private function normalizeUnit(string $unit): string
    {
        $unit = mb_strtolower(trim($unit));
        $unit = str_replace([' ', '.', '³', '²'], ['', '', '3', '2'], $unit);

        return match ($unit) {
            'кубм', 'куб.м', 'м^3' => 'м3',
            'квм', 'кв.м', 'м^2' => 'м2',
            'челч', 'чел.-ч', 'чел/ч' => 'чел-ч',
            'машч', 'маш.-ч', 'маш/ч' => 'маш-ч',
            default => $unit,
        };
    }

    private function collectionMatchesScope(string $normType, string $scopeType): bool
    {
        if ($scopeType === 'engineering') {
            return in_array($normType, ['gesnm', 'gesnp'], true);
        }

        return in_array($normType, ['gesn', 'gesnr'], true);
    }
}
