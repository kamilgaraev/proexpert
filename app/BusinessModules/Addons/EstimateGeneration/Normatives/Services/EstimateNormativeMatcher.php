<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\Normatives\NormativeSearchProfileData;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Normatives\WorkIntentData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums\EstimateImportStatus;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums\EstimateResourceType;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums\EstimateSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums\RegionalPriceStatus;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateDatasetVersion;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateNorm;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateNormResource;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateRegionalPriceVersion;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateResourcePrice;
use App\BusinessModules\Addons\EstimateGeneration\Services\Learning\EstimateGenerationLearningEvidenceService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeCandidateSearchService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeSearchProfileCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeSemanticCompatibilityService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeUnitNormalizer;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\WorkIntentClassifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class EstimateNormativeMatcher
{
    private const MAX_QUERY_TOKENS = 10;

    private const MIN_TOKEN_LENGTH = 3;

    private const LOW_CONFIDENCE_THRESHOLD = 0.55;

    private readonly NormativeCandidateSearchService $candidateSearchService;

    private readonly EstimateGenerationLearningEvidenceService $learningEvidenceService;

    private readonly WorkIntentClassifier $workIntentClassifier;

    private readonly NormativeSearchProfileCatalog $searchProfileCatalog;

    private readonly LegacyNormativeRateCatalogAdapter $legacyCatalogAdapter;

    private readonly NormativeSemanticCompatibilityService $semanticCompatibilityService;

    public function __construct(
        ?NormativeCandidateSearchService $candidateSearchService = null,
        ?EstimateGenerationLearningEvidenceService $learningEvidenceService = null,
        ?WorkIntentClassifier $workIntentClassifier = null,
        ?NormativeSearchProfileCatalog $searchProfileCatalog = null,
        ?LegacyNormativeRateCatalogAdapter $legacyCatalogAdapter = null,
        ?NormativeSemanticCompatibilityService $semanticCompatibilityService = null,
    ) {
        $this->candidateSearchService = $candidateSearchService ?? app(NormativeCandidateSearchService::class);
        $this->learningEvidenceService = $learningEvidenceService ?? app(EstimateGenerationLearningEvidenceService::class);
        $this->workIntentClassifier = $workIntentClassifier ?? app(WorkIntentClassifier::class);
        $this->searchProfileCatalog = $searchProfileCatalog ?? app(NormativeSearchProfileCatalog::class);
        $this->legacyCatalogAdapter = $legacyCatalogAdapter ?? app(LegacyNormativeRateCatalogAdapter::class);
        $this->semanticCompatibilityService = $semanticCompatibilityService ?? new NormativeSemanticCompatibilityService;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function matchWorkItem(array $workItem, array $context = [], int $limit = 5): ?array
    {
        $version = $this->latestFsnbVersion();
        $priceVersions = $this->latestPriceVersions($context);

        if ($version === null) {
            return $this->legacyCatalogAdapter->search($workItem, $context, $limit);
        }

        $intent = $this->workIntentClassifier->classify($workItem, $context);
        $profile = $this->searchProfileCatalog->forIntentData($intent);
        $workItem = [
            ...$workItem,
            'work_intent' => $this->intentPayload($intent),
        ];
        $tokens = $this->tokensForWorkItem($workItem, $context);
        $candidates = $this->candidateSearchService->search($version, $workItem, $context, $tokens, max($limit * 10, 50));

        if ($candidates->isEmpty()) {
            return $this->legacyCatalogAdapter->search($workItem, $context, $limit);
        }

        $learningEvidence = $this->learningEvidenceService->summarizeForCandidates($candidates, $workItem, $context);
        $ranked = $candidates
            ->map(fn (EstimateNorm $norm): array => $this->scoreNorm(
                $norm,
                $workItem,
                $context,
                $tokens,
                $priceVersions,
                $learningEvidence[(int) $norm->id] ?? $this->emptyLearningEvidence(),
                $intent,
                $profile
            ))
            ->filter(static fn (array $candidate): bool => (float) $candidate['score'] > 0)
            ->sortByDesc('score')
            ->values()
            ->take($limit)
            ->all();

        if ($ranked === []) {
            return $this->legacyCatalogAdapter->search($workItem, $context, $limit);
        }

        return [
            'version' => [
                'source_type' => $version->source_type->value,
                'version_key' => $version->version_key,
            ],
            'price_version' => $priceVersions->first() !== null ? [
                'source_type' => $priceVersions->first()->source_type->value,
                'version_key' => $priceVersions->first()->version_key,
            ] : null,
            'price_versions' => $priceVersions->map(static fn (EstimateDatasetVersion $version): array => [
                'source_type' => $version->source_type->value,
                'version_key' => $version->version_key,
            ])->values()->all(),
            'rerank' => [
                'status' => 'retrieval_only',
                'dataset_version' => $version->version_key,
                'scoring_version' => 'legacy-lexical-v1',
                'reranker_version' => null,
                'blocking_issues' => [],
            ],
            'selected' => $ranked[0],
            'candidates' => $ranked,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function searchWorkItemCandidates(array $workItem, array $context = [], int $limit = 10): ?array
    {
        $limit = max(1, min($limit, 20));
        $version = $this->latestFsnbVersion();
        $priceVersions = $this->latestPriceVersions($context);

        if ($version === null) {
            return $this->legacyCatalogAdapter->search($workItem, $context, $limit);
        }

        $intent = $this->workIntentClassifier->classify($workItem, $context);
        $profile = $this->searchProfileCatalog->forIntentData($intent);
        $workItem = [
            ...$workItem,
            'work_intent' => $this->intentPayload($intent),
        ];
        $tokens = $this->tokensForWorkItem($workItem, $context);
        $candidateLimit = max($limit * 2, 20);
        $poolLimit = max($limit * 6, 80);
        $candidates = $this->candidateSearchService->search(
            $version,
            $workItem,
            $context,
            $tokens,
            $candidateLimit,
            $poolLimit
        );

        if ($candidates->isEmpty()) {
            return $this->legacyCatalogAdapter->search($workItem, $context, $limit);
        }

        $learningEvidence = $this->learningEvidenceService->summarizeForCandidates($candidates, $workItem, $context);
        $ranked = $candidates
            ->map(fn (EstimateNorm $norm): array => $this->scoreNorm(
                $norm,
                $workItem,
                $context,
                $tokens,
                $priceVersions,
                $learningEvidence[(int) $norm->id] ?? $this->emptyLearningEvidence(),
                $intent,
                $profile
            ))
            ->filter(static fn (array $candidate): bool => (float) $candidate['score'] > 0)
            ->sortByDesc('score')
            ->values()
            ->take($limit)
            ->all();

        if ($ranked === []) {
            return $this->legacyCatalogAdapter->search($workItem, $context, $limit);
        }

        return [
            'version' => [
                'source_type' => $version->source_type->value,
                'version_key' => $version->version_key,
            ],
            'price_version' => $priceVersions->first() !== null ? [
                'source_type' => $priceVersions->first()->source_type->value,
                'version_key' => $priceVersions->first()->version_key,
            ] : null,
            'price_versions' => $priceVersions->map(static fn (EstimateDatasetVersion $version): array => [
                'source_type' => $version->source_type->value,
                'version_key' => $version->version_key,
            ])->values()->all(),
            'rerank' => null,
            'selected' => $ranked[0],
            'candidates' => $ranked,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function matchSelectedNorm(int $normId, array $workItem, array $context = []): ?array
    {
        $pinnedVersion = $context['normative_dataset_version'] ?? null;
        $version = is_string($pinnedVersion) && $pinnedVersion !== ''
            ? EstimateDatasetVersion::query()
                ->where('source_type', EstimateSourceType::FSNB_2022->value)
                ->where('status', EstimateImportStatus::PARSED->value)
                ->where('version_key', $pinnedVersion)
                ->first()
            : null;
        $priceVersions = $this->latestPriceVersions($context);

        if ($version === null) {
            return $this->legacyCatalogAdapter->find($normId, $workItem, $context);
        }

        $norm = EstimateNorm::query()
            ->with(['collection', 'section'])
            ->whereKey($normId)
            ->whereHas('collection', static function (Builder $query) use ($version): void {
                $query->where('dataset_version_id', $version->id);
            })
            ->first();

        if (! $norm instanceof EstimateNorm) {
            return null;
        }

        $intent = $this->workIntentClassifier->classify($workItem, $context);
        $profile = $this->searchProfileCatalog->forIntentData($intent);
        $workItem = [
            ...$workItem,
            'work_intent' => $this->intentPayload($intent),
        ];
        $tokens = $this->tokensForWorkItem($workItem, $context);
        $learningEvidence = $this->learningEvidenceService->summarizeForCandidates(collect([$norm]), $workItem, $context);
        $candidate = $this->scoreNorm(
            $norm,
            $workItem,
            $context,
            $tokens,
            $priceVersions,
            $learningEvidence[(int) $norm->id] ?? $this->emptyLearningEvidence(),
            $intent,
            $profile
        );

        return [
            'version' => [
                'source_type' => $version->source_type->value,
                'version_key' => $version->version_key,
            ],
            'price_version' => $priceVersions->first() !== null ? [
                'source_type' => $priceVersions->first()->source_type->value,
                'version_key' => $priceVersions->first()->version_key,
            ] : null,
            'price_versions' => $priceVersions->map(static fn (EstimateDatasetVersion $version): array => [
                'source_type' => $version->source_type->value,
                'version_key' => $version->version_key,
            ])->values()->all(),
            'rerank' => null,
            'selected' => $candidate,
            'candidates' => [$candidate],
        ];
    }

    public function latestFsnbVersion(): ?EstimateDatasetVersion
    {
        return EstimateDatasetVersion::query()
            ->where('source_type', EstimateSourceType::FSNB_2022->value)
            ->where('status', EstimateImportStatus::PARSED->value)
            ->whereHas('normCollections.norms')
            ->latest('id')
            ->first();
    }

    public function latestFsbcVersion(): ?EstimateDatasetVersion
    {
        return $this->latestPriceVersion();
    }

    public function latestPriceVersion(): ?EstimateDatasetVersion
    {
        return $this->latestPriceVersions()->first();
    }

    public function latestPriceVersions(array $context = []): Collection
    {
        $fsbcVersion = EstimateDatasetVersion::query()
            ->where('source_type', EstimateSourceType::FSBC->value)
            ->where('status', EstimateImportStatus::PARSED->value)
            ->whereHas('resourcePrices')
            ->latest('id')
            ->first();

        $fallbackFsnbVersion = null;

        if ($fsbcVersion === null) {
            $fallbackFsnbVersion = EstimateDatasetVersion::query()
                ->where('source_type', EstimateSourceType::FSNB_2022->value)
                ->where('status', EstimateImportStatus::PARSED->value)
                ->whereHas('resourcePrices')
                ->latest('id')
                ->first();
        }

        $regionalVersion = $this->regionalPriceVersionForContext($context);
        $laborVersion = $regionalVersion !== null
            ? EstimateDatasetVersion::query()
                ->where('source_type', EstimateSourceType::FGIS_LABOR_PRICES->value)
                ->where('status', EstimateImportStatus::PARSED->value)
                ->whereHas('resourcePrices', static function (Builder $query) use ($regionalVersion): void {
                    $query->where('regional_price_version_id', $regionalVersion->id);
                })
                ->latest('id')
                ->first()
            : EstimateDatasetVersion::query()
                ->where('source_type', EstimateSourceType::FGIS_LABOR_PRICES->value)
                ->where('status', EstimateImportStatus::PARSED->value)
                ->whereHas('resourcePrices')
                ->latest('id')
                ->first();

        return collect([$fsbcVersion ?? $fallbackFsnbVersion, $laborVersion])->filter()->values();
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
     * @param  array<int, string>  $tokens
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
                    $like = '%'.mb_strtolower($token).'%';
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
     * @param  array<int, string>  $tokens
     * @return array<string, mixed>
     */
    private function scoreNorm(
        EstimateNorm $norm,
        array $workItem,
        array $context,
        array $tokens,
        Collection $priceVersions,
        array $learningEvidence,
        WorkIntentData $intent,
        NormativeSearchProfileData $profile
    ): array {
        $name = mb_strtolower($norm->name);
        $workName = mb_strtolower(trim((string) ($workItem['normative_search_text'] ?? $workItem['name'] ?? '')));
        $section = mb_strtolower((string) ($norm->section_name ?? ''));
        $composition = mb_strtolower(implode(' ', $norm->work_composition ?? []));
        $score = 0.0;
        $reasons = [];

        if ($workName !== '' && $workName === $name) {
            $score += 32;
            $reasons[] = 'exact_name';
        } elseif ($workName !== '' && (str_contains($name, $workName) || str_contains($workName, $name))) {
            $score += 14;
            $reasons[] = 'name_phrase';
        }

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

        $scopeMismatch = ! $this->intentAllowsNorm($intent, $norm);
        if ($scopeMismatch) {
            $score -= 120;
            $reasons[] = 'scope_mismatch';
        } elseif ($this->sectionStartsWithAny((string) ($norm->section_code ?? $norm->section?->code ?? ''), $profile->allowedSectionPrefixes)) {
            $score += 20;
            $reasons[] = 'search_profile_section';
        }

        $profileTermScore = $this->profileTermScore($name.' '.$section.' '.$composition, $profile);
        if ($profileTermScore > 0) {
            $score += $profileTermScore;
            $reasons[] = 'search_profile_terms';
        }

        $semanticMismatch = ! $this->semanticCompatibilityService->isCompatible(
            $name.' '.$composition,
            $workName,
            [
                'scope' => $intent->scope,
                'action' => $intent->action,
                'system' => $intent->system,
                'object' => $intent->object,
                'candidate_title' => $name,
            ],
            $profile->forbiddenDomainTerms,
        );
        if ($semanticMismatch) {
            $score -= 300;
            $reasons[] = 'semantic_mismatch';
        } else {
            $reasons[] = 'semantic_compatible';
        }

        $learningScore = (float) ($learningEvidence['learning_score'] ?? 0);
        $learningPositiveCount = (int) ($learningEvidence['learning_positive_count'] ?? 0);
        $learningNegativeCount = (int) ($learningEvidence['learning_negative_count'] ?? 0);
        if ($learningScore !== 0.0) {
            $score += max(-45.0, min(32.0, $learningScore));
        }

        if ($learningPositiveCount > 0) {
            $reasons[] = 'learning_positive_evidence';
        }

        if ($learningNegativeCount > 0) {
            $reasons[] = 'learning_negative_evidence';
        }

        $resources = $this->resourcesForNorm($norm, $priceVersions, $context);
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
            'key' => 'norm-'.$norm->id,
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
            'warnings' => $this->warningsForCandidate(
                $confidence,
                $resourceCount,
                $pricedCount,
                ! $unitMatches,
                $scopeMismatch,
                $learningNegativeCount,
                $semanticMismatch,
            ),
            'resources' => $resources,
            'learning_positive_count' => $learningPositiveCount,
            'learning_negative_count' => $learningNegativeCount,
            'learning_score' => round($learningScore, 2),
            'learning_sources' => array_values($learningEvidence['learning_sources'] ?? []),
        ];
    }

    private function profileTermScore(string $text, NormativeSearchProfileData $profile): float
    {
        $score = 0.0;

        foreach ($profile->requiredTerms as $term) {
            if ($term !== '' && str_contains($text, $term)) {
                $score += 8.0;
            }
        }

        foreach ($profile->synonymTerms as $term) {
            if ($term !== '' && str_contains($text, $term)) {
                $score += 3.0;
            }
        }

        return min($score, 36.0);
    }

    /**
     * @return array{materials: array<int, array<string, mixed>>, machinery: array<int, array<string, mixed>>, labor: array<int, array<string, mixed>>, other: array<int, array<string, mixed>>}
     */
    private function resourcesForNorm(EstimateNorm $norm, Collection $priceVersions, array $context = []): array
    {
        $resources = EstimateNormResource::query()
            ->where('estimate_norm_id', $norm->id)
            ->where('resource_type', '<>', EstimateResourceType::SUMMARY->value)
            ->orderBy('id')
            ->limit(120)
            ->get();

        $regionalPriceVersionId = $this->regionalPriceVersionIdFromContext($context);
        $prices = $priceVersions->isNotEmpty()
            ? EstimateResourcePrice::query()
                ->whereIn('dataset_version_id', $priceVersions->pluck('id')->values()->all())
                ->whereIn('resource_code', $resources->pluck('resource_code')->filter()->values()->all())
                ->when($regionalPriceVersionId !== null, static function (Builder $query) use ($regionalPriceVersionId): void {
                    $query->where(function (Builder $query) use ($regionalPriceVersionId): void {
                        $query->whereNull('regional_price_version_id')
                            ->orWhere('regional_price_version_id', $regionalPriceVersionId);
                    });
                })
                ->when(
                    $regionalPriceVersionId !== null,
                    static fn (Builder $query): Builder => $query->orderByRaw('CASE WHEN regional_price_version_id IS NULL THEN 1 ELSE 0 END')
                )
                ->orderByDesc('dataset_version_id')
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
            $unitPrice = $this->effectiveUnitPrice($price, $type, (string) ($resource->unit ?? ''));
            $payload = [
                'code' => $resource->resource_code,
                'name' => $resource->resource_name,
                'resource_type' => $type,
                'unit' => $resource->unit,
                'price_unit' => $price?->unit,
                'quantity' => $resource->quantity !== null ? (float) $resource->quantity : null,
                'unit_price' => $unitPrice,
                'total_price' => $price !== null && $resource->quantity !== null
                    ? round($unitPrice * (float) $resource->quantity, 2)
                    : 0.0,
                'price_source' => $price !== null && $price->datasetVersion !== null
                    ? $price->datasetVersion->source_type->value.'_base'
                    : null,
                'price_id' => $price?->id,
                'norm_resource_id' => $resource->id,
                'linked_resource_id' => $resource->construction_resource_id,
                'pricing' => $this->pricePayload($price),
            ];

            if ($type === EstimateResourceType::MATERIAL->value || $type === EstimateResourceType::EQUIPMENT->value) {
                $grouped['materials'][] = $payload;

                continue;
            }

            if ($type === EstimateResourceType::MACHINE->value) {
                $grouped['machinery'][] = $payload;
                $machineLabor = $this->machineLaborPayload($payload);

                if ($machineLabor !== null) {
                    $grouped['labor'][] = $machineLabor;
                }

                continue;
            }

            if ($type === EstimateResourceType::LABOR->value || $type === EstimateResourceType::MACHINE_LABOR->value) {
                $grouped['labor'][] = $payload;

                continue;
            }

            $grouped['other'][] = $payload;
        }

        return $grouped;
    }

    /**
     * @param  array{materials: array<int, array<string, mixed>>, machinery: array<int, array<string, mixed>>, labor: array<int, array<string, mixed>>, other: array<int, array<string, mixed>>}  $resources
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
     * @param  array<string, mixed>  $resource
     */
    private function resourceHasPositivePrice(array $resource): bool
    {
        return ($resource['price_source'] ?? null) !== null && $this->resourceTotalPrice($resource) > 0;
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
     * @return array<int, string>
     */
    private function warningsForCandidate(
        float $confidence,
        int $resourceCount,
        int $pricedCount,
        bool $unitMismatch,
        bool $scopeMismatch,
        int $learningNegativeCount = 0,
        bool $semanticMismatch = false,
    ): array {
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

        if ($resourceCount > 0 && $pricedCount > 0 && $pricedCount < $resourceCount) {
            $warnings[] = 'norm_with_unpriced_resources';
        }

        if ($unitMismatch) {
            $warnings[] = 'unit_mismatch';
        }

        if ($scopeMismatch) {
            $warnings[] = 'scope_mismatch';
        }

        if ($semanticMismatch) {
            $warnings[] = 'semantic_mismatch';
        }

        if ($learningNegativeCount > 0) {
            $warnings[] = 'learning_negative_evidence';
        }

        return $warnings;
    }

    private function intentAllowsNorm(WorkIntentData $intent, EstimateNorm $norm): bool
    {
        $sectionCode = (string) ($norm->section_code ?? $norm->section?->code ?? '');
        $normType = (string) ($norm->collection?->norm_type?->value ?? $norm->collection?->norm_type ?? '');

        if ($normType !== '' && in_array($normType, $intent->forbiddenNormTypes, true)) {
            return false;
        }

        foreach ($intent->forbiddenSectionPrefixes as $prefix) {
            if ($sectionCode !== '' && str_starts_with($sectionCode, $prefix)) {
                return false;
            }
        }

        if ($intent->preferredSectionPrefixes !== [] && ! $this->sectionStartsWithAny($sectionCode, $intent->preferredSectionPrefixes)) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<int, string>  $prefixes
     */
    private function sectionStartsWithAny(string $sectionCode, array $prefixes): bool
    {
        if ($sectionCode === '') {
            return false;
        }

        foreach ($prefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($sectionCode, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function intentPayload(WorkIntentData $intent): array
    {
        return [
            'scope' => $intent->scope,
            'action' => $intent->action,
            'object' => $intent->object,
            'material' => $intent->material,
            'system' => $intent->system,
            'expected_dimensions' => $intent->expectedDimensions,
            'preferred_norm_types' => $intent->preferredNormTypes,
            'forbidden_norm_types' => $intent->forbiddenNormTypes,
            'preferred_section_prefixes' => $intent->preferredSectionPrefixes,
            'forbidden_section_prefixes' => $intent->forbiddenSectionPrefixes,
            'confidence' => $intent->confidence,
            'signals' => $intent->signals,
        ];
    }

    /**
     * @return array{learning_positive_count: int, learning_negative_count: int, learning_score: float, learning_sources: array<int, array<string, mixed>>}
     */
    private function emptyLearningEvidence(): array
    {
        return [
            'learning_positive_count' => 0,
            'learning_negative_count' => 0,
            'learning_score' => 0.0,
            'learning_sources' => [],
        ];
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

    private function effectiveUnitPrice(?EstimateResourcePrice $price, string $resourceType, string $resourceUnit = ''): float
    {
        if ($price === null) {
            return 0.0;
        }

        if ($resourceType === EstimateResourceType::MACHINE->value && $price->machine_price_without_salary !== null) {
            $basePrice = (float) $price->machine_price_without_salary;
        } else {
            $basePrice = $price->base_price !== null ? (float) $price->base_price : 0.0;
        }

        return $basePrice * NormativeUnitNormalizer::quantityFactor($resourceUnit, (string) ($price->unit ?? ''));
    }

    private function pricePayload(?EstimateResourcePrice $price): array
    {
        return [
            'base_price' => $price?->base_price !== null ? (float) $price->base_price : 0.0,
            'unit' => $price?->unit,
            'machine_salary_price' => $price?->machine_salary_price !== null ? (float) $price->machine_salary_price : null,
            'machine_price_without_salary' => $price?->machine_price_without_salary !== null ? (float) $price->machine_price_without_salary : null,
            'machine_labor_quantity' => $price?->machine_labor_quantity !== null ? (float) $price->machine_labor_quantity : null,
            'driver_code' => $price?->driver_code,
            'machinist_category' => $price?->machinist_category,
            'source_price_kind' => $price?->source_price_kind,
        ];
    }

    private function machineLaborPayload(array $machineResource): ?array
    {
        $pricing = $machineResource['pricing'] ?? [];
        $driverCode = $pricing['driver_code'] ?? null;
        $machinistCategory = $pricing['machinist_category'] ?? null;
        $machineLaborQuantity = (float) ($pricing['machine_labor_quantity'] ?? 0);
        $machineSalaryPrice = (float) ($pricing['machine_salary_price'] ?? 0);
        $machineQuantity = $machineResource['quantity'] !== null ? (float) $machineResource['quantity'] : 0.0;

        if ($driverCode === null || $machineLaborQuantity <= 0 || $machineSalaryPrice <= 0 || $machineQuantity <= 0) {
            return null;
        }

        $quantity = round($machineQuantity * $machineLaborQuantity, 6);

        return [
            'code' => $driverCode,
            'name' => trim('ОТм(ЗТм) Средний разряд машинистов '.(string) $machinistCategory),
            'resource_type' => EstimateResourceType::MACHINE_LABOR->value,
            'unit' => 'чел.-ч',
            'quantity' => $quantity,
            'unit_price' => $machineSalaryPrice,
            'total_price' => round($quantity * $machineSalaryPrice, 2),
            'price_source' => $machineResource['price_source'] ?? null,
            'price_id' => null,
            'linked_resource_id' => null,
            'pricing' => [
                'base_price' => $machineSalaryPrice,
                'driver_code' => $driverCode,
                'machinist_category' => $machinistCategory,
                'source_price_kind' => 'fsbc_machine_salary',
            ],
        ];
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
        return NormativeUnitNormalizer::compatible($left, $right);
    }

    private function collectionMatchesScope(string $normType, string $scopeType): bool
    {
        if (in_array($scopeType, ['engineering', 'electrical', 'plumbing', 'heating', 'ventilation'], true)) {
            return in_array($normType, ['gesnm', 'gesnp'], true);
        }

        return in_array($normType, ['gesn', 'gesnr'], true);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function regionalPriceVersionForContext(array $context): ?EstimateRegionalPriceVersion
    {
        $context = $this->regionalContext($context);
        $versionId = $this->regionalPriceVersionIdFromContext($context);

        if ($versionId !== null) {
            return EstimateRegionalPriceVersion::query()
                ->with(['region', 'priceZone', 'period'])
                ->find($versionId);
        }

        $regionId = $this->nullableInt($context['region_id'] ?? null);
        if ($regionId === null) {
            return null;
        }

        $query = EstimateRegionalPriceVersion::query()
            ->with(['region', 'priceZone', 'period'])
            ->where('region_id', $regionId)
            ->whereIn('status', [
                RegionalPriceStatus::ACTIVE->value,
                RegionalPriceStatus::CHECKED->value,
                RegionalPriceStatus::PARSED->value,
            ]);

        $priceZoneId = $this->nullableInt($context['price_zone_id'] ?? null);
        if ($priceZoneId !== null) {
            $query->where('price_zone_id', $priceZoneId);
        }

        $periodId = $this->nullableInt($context['period_id'] ?? null);
        if ($periodId !== null) {
            $query->where('period_id', $periodId);
        } elseif (($context['year'] ?? null) !== null && ($context['quarter'] ?? null) !== null) {
            $year = (int) $context['year'];
            $quarter = (int) $context['quarter'];
            $query->whereHas('period', static function (Builder $query) use ($year, $quarter): void {
                $query->where('year', $year)->where('quarter', $quarter);
            });
        }

        return $query
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'checked' THEN 1 WHEN 'parsed' THEN 2 ELSE 3 END")
            ->latest('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function regionalPriceVersionIdFromContext(array $context): ?int
    {
        $context = $this->regionalContext($context);

        return $this->nullableInt($context['estimate_regional_price_version_id'] ?? $context['version_id'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function regionalContext(array $context): array
    {
        if (is_array($context['regional_context'] ?? null)) {
            return $context['regional_context'];
        }

        return $context;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
