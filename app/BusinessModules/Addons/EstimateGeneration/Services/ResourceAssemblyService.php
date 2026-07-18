<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\AcceptedNormativeDecisionData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\EstimateNormativeMatcher;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeCandidatePresenter;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeMatchDecisionService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeUnitNormalizer;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\WorkIntentClassifier;

use function trans_message;

class ResourceAssemblyService
{
    private const LOW_CONFIDENCE_THRESHOLD = 0.55;

    private const PROGRESS_STEP = 10;

    public function __construct(
        protected EstimateNormativeMatcher $normativeMatcher,
        protected NormativeMatchDecisionService $matchDecisionService,
        protected NormativeCandidatePresenter $candidatePresenter,
        protected ?WorkIntentClassifier $workIntentClassifier = null,
        protected EstimateGenerationNoAirWorkItemPolicy $noAirWorkItemPolicy = new EstimateGenerationNoAirWorkItemPolicy,
    ) {}

    /**
     * @param  array<string, mixed>  $workItem
     * @param  array<string, mixed>  $regionalContext
     * @return array<string, mixed>
     */
    public function assembleFromDecision(array $workItem, AcceptedNormativeDecisionData $decision, array $regionalContext): array
    {
        foreach (['dataset_id', 'dataset_version', 'region_id', 'price_zone_id', 'period_id', 'price_version'] as $key) {
            if (! array_key_exists($key, $regionalContext) || $regionalContext[$key] === null || $regionalContext[$key] === '') {
                throw new \InvalidArgumentException('accepted_normative_regional_context_incomplete');
            }
        }
        if ((int) $regionalContext['dataset_id'] !== $decision->datasetId
            || (string) $regionalContext['dataset_version'] !== $decision->datasetVersion) {
            throw new \InvalidArgumentException('accepted_normative_dataset_mismatch');
        }
        $selected = [
            'key' => $decision->candidateId, 'norm_id' => $decision->normativeId, 'code' => $decision->code,
            'name' => $decision->name, 'unit' => $decision->unit, 'collection' => $decision->collection,
            'section' => $decision->section, 'score' => $decision->score, 'confidence' => $decision->confidence,
            'match_reasons' => $decision->matchReasons, 'warnings' => $decision->warnings,
            'work_composition' => $decision->workComposition, 'resources' => $decision->resources,
        ];
        $match = [
            'version' => ['source_type' => 'fsnb', 'version_key' => $decision->datasetVersion],
            'price_version' => null, 'selected' => $selected, 'candidates' => [$selected],
        ];
        $match['price_version'] = [
            'source_type' => 'regional_catalog',
            'version_key' => (string) $regionalContext['price_version'],
        ];

        return $this->assembleAcceptedDecision($workItem, $decision, $match, [
            'status' => 'accepted', 'can_use_for_pricing' => true, 'confidence' => $decision->confidence,
            'reasons' => $decision->matchReasons, 'warnings' => $decision->warnings,
        ], false);
    }

    public function enrich(array $workItems, array $context = []): array
    {
        $progressCallback = is_callable($context['progress_callback'] ?? null) ? $context['progress_callback'] : null;
        $total = count($workItems);
        $matchCache = [];

        foreach ($workItems as $index => &$workItem) {
            if ($this->isQuantityReviewItem($workItem)) {
                $workItem = $this->clearQuantityReviewPricing($workItem);
                $processed = $index + 1;

                if ($progressCallback !== null && ($processed % self::PROGRESS_STEP === 0 || $processed === $total)) {
                    $progressCallback($processed, $total);
                }

                continue;
            }

            if ($this->noAirWorkItemPolicy->requiresReview($workItem)) {
                $workItem = $this->markNoAirWorkItem($workItem);
                $processed = $index + 1;

                if ($progressCallback !== null && ($processed % self::PROGRESS_STEP === 0 || $processed === $total)) {
                    $progressCallback($processed, $total);
                }

                continue;
            }

            if ($this->requiresDocumentTakeoff($workItem)) {
                $workItem = $this->markDocumentTakeoffRequired($workItem);
                $processed = $index + 1;

                if ($progressCallback !== null && ($processed % self::PROGRESS_STEP === 0 || $processed === $total)) {
                    $progressCallback($processed, $total);
                }

                continue;
            }

            if (($workItem['skip_normative_matching'] ?? false) === true || ! $this->isPricedItem($workItem)) {
                $processed = $index + 1;

                if ($progressCallback !== null && ($processed % self::PROGRESS_STEP === 0 || $processed === $total)) {
                    $progressCallback($processed, $total);
                }

                continue;
            }

            $workItemForMatching = $this->withWorkIntent($this->workItemForMatching($workItem), $context);
            $workItem['work_intent'] = $workItemForMatching['work_intent'];
            $cacheKey = $this->matchCacheKey($workItem, $context);
            $selectedNormId = $context['selected_norm_id'] ?? null;
            $match = is_int($selectedNormId) || (is_string($selectedNormId) && ctype_digit($selectedNormId))
                ? $this->normativeMatcher->matchSelectedNorm((int) $selectedNormId, $workItemForMatching, $context)
                : (array_key_exists($cacheKey, $matchCache)
                    ? $matchCache[$cacheKey]
                    : $matchCache[$cacheKey] = $this->normativeMatcher->matchWorkItem($workItemForMatching, $context));

            if ($match === null) {
                $workItem = $this->markUnmatched($workItem);
            } else {
                $workItem = $this->applyNormativeMatch($workItem, $match);
            }

            $processed = $index + 1;

            if ($progressCallback !== null && ($processed % self::PROGRESS_STEP === 0 || $processed === $total)) {
                $progressCallback($processed, $total);
            }
        }
        unset($workItem);

        return $workItems;
    }

    /**
     * @param  array<string, mixed>  $workItem
     * @param  array<string, mixed>  $match
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function applySelectedNormativeMatch(array $workItem, array $match, array $context = []): array
    {
        $workItem = $this->withWorkIntent($workItem, $context);
        $workItem = $this->applyDecidedNormativeMatch($workItem, $match, true);

        return $this->noAirWorkItemPolicy->requiresReview($workItem)
            ? $this->markNoAirWorkItem($workItem)
            : $workItem;
    }

    /**
     * @param  array<string, mixed>  $workItem
     */
    private function isPricedItem(array $workItem): bool
    {
        return ! in_array((string) ($workItem['item_type'] ?? 'priced_work'), ['operation', 'resource_note', 'review_note', 'quantity_review'], true);
    }

    /**
     * @param  array<string, mixed>  $workItem
     */
    private function isQuantityReviewItem(array $workItem): bool
    {
        $flags = array_values(array_filter(array_map('strval', [
            ...(is_array($workItem['validation_flags'] ?? null) ? $workItem['validation_flags'] : []),
            ...(is_array($workItem['flags'] ?? null) ? $workItem['flags'] : []),
        ])));

        return (string) ($workItem['item_type'] ?? '') === 'quantity_review'
            || (string) ($workItem['pricing_blocker'] ?? '') === 'quantity_review_required'
            || in_array('quantity_review_required', $flags, true);
    }

    /**
     * @param  array<string, mixed>  $workItem
     * @return array<string, mixed>
     */
    private function clearQuantityReviewPricing(array $workItem): array
    {
        $workItem = $this->clearNonNormativeResources($workItem);
        $workItem['item_type'] = 'quantity_review';
        $workItem['normative_rate_code'] = null;
        $workItem['normative_dataset'] = null;
        $workItem['normative_match'] = null;
        $workItem['price_dataset'] = null;
        $workItem['pricing_status'] = 'not_calculated';
        $workItem['pricing_blocker'] = 'quantity_review_required';
        $workItem['pricing_blocker_message'] = null;
        $workItem['validation_flags'] = array_values(array_unique([
            ...(is_array($workItem['validation_flags'] ?? null) ? $workItem['validation_flags'] : []),
            'quantity_review_required',
            'pricing_not_calculated',
        ]));

        return $workItem;
    }

    /**
     * @param  array<string, mixed>  $workItem
     * @return array<string, mixed>
     */
    private function markNoAirWorkItem(array $workItem): array
    {
        return $this->noAirWorkItemPolicy->markRequiresReview(
            $workItem,
            trans_message('estimate_generation.pricing_not_calculated_safe_norm')
        );
    }

    /**
     * @param  array<string, mixed>  $workItem
     */
    private function requiresDocumentTakeoff(array $workItem): bool
    {
        $flags = array_values(array_filter(array_map('strval', [
            ...(is_array($workItem['validation_flags'] ?? null) ? $workItem['validation_flags'] : []),
            ...(is_array($workItem['flags'] ?? null) ? $workItem['flags'] : []),
        ])));

        return (string) ($workItem['pricing_blocker'] ?? '') === 'document_takeoff_required'
            || in_array('document_takeoff_required', $flags, true);
    }

    /**
     * @param  array<string, mixed>  $workItem
     * @return array<string, mixed>
     */
    private function markDocumentTakeoffRequired(array $workItem): array
    {
        $workItem = $this->clearNonNormativeResources($workItem);
        $workItem['price_source'] = null;
        $workItem['pricing_status'] = 'not_calculated';
        $workItem['pricing_blocker'] = 'document_takeoff_required';
        $workItem['pricing_blocker_message'] = trans_message('estimate_generation.pricing_not_calculated_safe_norm');
        $workItem['validation_flags'] = array_values(array_unique([
            ...(is_array($workItem['validation_flags'] ?? null) ? $workItem['validation_flags'] : []),
            'normative_required',
            'requires_normative_review',
            'document_takeoff_required',
            'pricing_not_calculated',
        ]));

        return $workItem;
    }

    /**
     * @param  array<string, mixed>  $workItem
     * @param  array<string, mixed>  $context
     */
    private function matchCacheKey(array $workItem, array $context): string
    {
        $regionalContext = is_array($context['regional_context'] ?? null) ? $context['regional_context'] : [];

        return implode('|', [
            (string) ($regionalContext['estimate_regional_price_version_id'] ?? $regionalContext['version_id'] ?? 'base'),
            (string) ($context['scope_type'] ?? ''),
            (string) ($workItem['normative_search_key'] ?? $this->fallbackSearchKey($workItem)),
        ]);
    }

    /**
     * @param  array<string, mixed>  $workItem
     * @return array<string, mixed>
     */
    private function workItemForMatching(array $workItem): array
    {
        if (($workItem['normative_search_text'] ?? null) === null) {
            return $workItem;
        }

        return [
            ...$workItem,
            'name' => $workItem['normative_search_text'],
            'description' => $workItem['normative_search_text'],
        ];
    }

    /**
     * @param  array<string, mixed>  $workItem
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function withWorkIntent(array $workItem, array $context): array
    {
        if (is_array($workItem['work_intent'] ?? null)) {
            return $workItem;
        }

        $this->workIntentClassifier ??= app(WorkIntentClassifier::class);
        $intent = $this->workIntentClassifier->classify($workItem, $context);

        return [
            ...$workItem,
            'work_intent' => [
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
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $workItem
     */
    private function fallbackSearchKey(array $workItem): string
    {
        $name = (string) ($workItem['name'] ?? '');
        $name = preg_replace('/:\s*.+$/u', '', $name) ?? $name;

        return implode('|', [
            (string) ($workItem['work_category'] ?? ''),
            mb_strtolower(trim($name)),
            (string) ($workItem['unit'] ?? ''),
        ]);
    }

    /**
     * @param  array<string, mixed>  $workItem
     * @param  array<string, mixed>  $match
     * @return array<string, mixed>
     */
    private function applyNormativeMatch(array $workItem, array $match): array
    {
        return $this->applyDecidedNormativeMatch($workItem, $match, false);
    }

    /**
     * @param  array<string, mixed>  $workItem
     * @param  array<string, mixed>  $match
     * @return array<string, mixed>
     */
    private function applyDecidedNormativeMatch(array $workItem, array $match, bool $selectedByUser): array
    {
        $selected = $match['selected'];
        $decision = $this->matchDecisionService->decide($selected, $workItem);

        if (! $decision->canUseForPricing) {
            return $this->applyCandidateOnlyMatch($workItem, $match, $decision->toArray(), $selectedByUser);
        }

        $decisionPayload = $decision->toArray();
        $accepted = AcceptedNormativeDecisionData::fromAcceptedCatalogMatch($match, $decisionPayload);
        $workItem = $this->assembleAcceptedDecision($workItem, $accepted, $match, $decisionPayload, $selectedByUser);
        $flags = $this->acceptedFlags($workItem['validation_flags'] ?? []);

        if ($decision->status === 'review_priced') {
            $flags[] = 'requires_normative_review';
            $flags[] = 'safe_normative_analog';
        } elseif ($decision->status !== 'accepted') {
            $flags[] = 'normative_candidate_only';
            $flags[] = 'requires_normative_review';
        }

        if ((float) $selected['confidence'] < self::LOW_CONFIDENCE_THRESHOLD || in_array('low_confidence', $decision->warnings, true)) {
            $flags[] = 'normative_match_low_confidence';
        }

        if ($workItem['materials'] === [] && $workItem['labor'] === [] && $workItem['machinery'] === []) {
            $flags[] = 'normative_resources_empty';
        }

        if ($this->pricedResourcesCount($selected['resources'] ?? []) === 0) {
            $flags[] = 'normative_prices_missing';
            $flags[] = 'normative_price_required';
        }

        $workItem['validation_flags'] = array_values(array_unique($flags));

        return $workItem;
    }

    /**
     * @param  array<string, mixed>  $workItem
     * @param  array<string, mixed>  $match
     * @param  array<string, mixed>  $decision
     * @return array<string, mixed>
     */
    private function assembleAcceptedDecision(
        array $workItem,
        AcceptedNormativeDecisionData $accepted,
        array $match,
        array $decision,
        bool $selectedByUser,
    ): array {
        if ((int) ($match['selected']['norm_id'] ?? 0) !== $accepted->normativeId
            || (string) ($match['version']['version_key'] ?? '') !== $accepted->datasetVersion) {
            throw new \InvalidArgumentException('accepted_normative_decision_mismatch');
        }

        return $this->applyNormativeResources($workItem, $match, $accepted, $selectedByUser, $decision);
    }

    /**
     * @param  array<string, mixed>  $workItem
     * @param  array<string, mixed>  $match
     * @return array<string, mixed>
     */
    private function applyNormativeResources(
        array $workItem,
        array $match,
        AcceptedNormativeDecisionData $accepted,
        bool $selectedByUser = false,
        ?array $decision = null,
    ): array {
        $selected = $match['selected'];
        $version = $match['version'];
        $priceVersion = $match['price_version'] ?? null;
        $quantityFactor = NormativeUnitNormalizer::safeQuantityFactor(
            (string) ($workItem['unit'] ?? ''),
            (string) ($selected['unit'] ?? '')
        );

        if ($quantityFactor === null) {
            return $this->applyCandidateOnlyMatch($workItem, $match, [
                'status' => 'candidate',
                'can_use_for_pricing' => false,
                'confidence' => (float) ($selected['confidence'] ?? 0),
                'reasons' => [],
                'warnings' => ['unit_mismatch'],
            ], $selectedByUser);
        }

        $normQuantity = max((float) ($workItem['quantity'] ?? 0), 0.0) * $quantityFactor;
        $resources = $selected['resources'];
        $workItem = $this->clearNonNormativeResources($workItem);

        $workItem['materials'] = $this->mapResources($resources['materials'] ?? [], 'material', $normQuantity, $selected, $version, $workItem);
        $workItem['labor'] = $this->mapResources($resources['labor'] ?? [], 'labor', $normQuantity, $selected, $version, $workItem);
        $workItem['machinery'] = $this->mapResources($resources['machinery'] ?? [], 'machinery', $normQuantity, $selected, $version, $workItem);
        $workItem['other_resources'] = $this->mapResources($resources['other'] ?? [], 'other', $normQuantity, $selected, $version, $workItem);
        $workItem['normative_rate_code'] = $selected['code'];
        $workItem['normative_dataset'] = $version;
        $workItem['price_dataset'] = $priceVersion;
        $workItem['price_source'] = 'fsnb_normative';
        $workItem['pricing_status'] = ($decision['status'] ?? null) === 'review_priced'
            ? 'calculated_review_required'
            : 'calculated';
        $workItem['pricing_blocker'] = null;
        $workItem['pricing_blocker_message'] = null;
        $unpricedAbstractResources = $accepted->unpricedAbstractResources;
        $warnings = array_values(array_unique([
            ...($selected['warnings'] ?? []),
            ...($decision['warnings'] ?? []),
            ...($unpricedAbstractResources !== [] ? ['project_resource_selection_required'] : []),
        ]));

        $workItem['normative_match'] = [
            'status' => 'matched',
            'selected_by_user' => $selectedByUser,
            'selected_candidate_key' => $selected['key'],
            'norm_id' => $selected['norm_id'],
            'catalog_source' => $selected['catalog_source'] ?? 'estimate_norms',
            'normative_rate_id' => $selected['normative_rate_id'] ?? null,
            'code' => $selected['code'],
            'name' => $selected['name'],
            'unit' => $selected['unit'],
            'collection' => $selected['collection'],
            'section' => $selected['section'],
            'dataset_version' => $version,
            'price_version' => $priceVersion,
            'score' => $selected['score'],
            'confidence' => $selected['confidence'],
            'match_reasons' => $selected['match_reasons'],
            'warnings' => $warnings,
            'decision' => $decision,
            'resources_count' => $this->resourcesCount($resources),
            'priced_resources_count' => $this->pricedResourcesCount($resources),
            'unpriced_abstract_resources' => $unpricedAbstractResources,
            'work_composition' => $this->normalizeComposition($selected['work_composition'] ?? []),
        ];
        $workItem = $this->applyNormativeComposition($workItem, $selected);
        $freshCandidates = array_map(
            fn (array $candidate): array => $this->candidateSummary($candidate, $workItem),
            $match['candidates']
        );
        $workItem['normative_candidates'] = $this->mergeNormativeCandidates(
            $freshCandidates,
            is_array($workItem['normative_candidates'] ?? null) ? $workItem['normative_candidates'] : []
        );
        $workItem['confidence'] = round(((float) ($workItem['confidence'] ?? 0.5) + (float) $selected['confidence']) / 2, 4);
        $workItem['validation_flags'] = $this->acceptedFlags($workItem['validation_flags'] ?? []);

        return $workItem;
    }

    /**
     * @param  array<string, mixed>  $workItem
     * @param  array<string, mixed>  $match
     * @param  array<string, mixed>  $decision
     * @return array<string, mixed>
     */
    private function applyCandidateOnlyMatch(array $workItem, array $match, array $decision, bool $selectedByUser = false): array
    {
        $selected = $match['selected'];
        $flags = $workItem['validation_flags'] ?? [];
        $flags[] = 'normative_candidate_only';
        $flags[] = 'requires_normative_review';
        $flags[] = 'safe_norm_required';
        $flags[] = 'pricing_not_calculated';
        $workItem = $this->clearNonNormativeResources($workItem);

        if (in_array('low_confidence', $decision['warnings'] ?? [], true)) {
            $flags[] = 'normative_match_low_confidence';
        }

        foreach ($this->hardWarningFlags($decision['warnings'] ?? []) as $flag) {
            $flags[] = $flag;
        }

        $pricingBlocker = $this->pricingBlocker($decision['warnings'] ?? []);
        if ($this->requiresNormativePriceSelection($decision['warnings'] ?? [])) {
            $flags[] = 'normative_price_required';
        }

        $workItem['pricing_status'] = 'not_calculated';
        $workItem['pricing_blocker'] = $pricingBlocker;
        $workItem['pricing_blocker_message'] = trans_message($this->pricingBlockerMessageKey($pricingBlocker));

        $workItem['normative_match'] = [
            'status' => $decision['status'],
            'selected_by_user' => $selectedByUser,
            'selected_candidate_key' => $selected['key'],
            'norm_id' => $selected['norm_id'],
            'catalog_source' => $selected['catalog_source'] ?? 'estimate_norms',
            'normative_rate_id' => $selected['normative_rate_id'] ?? null,
            'code' => $selected['code'],
            'name' => $selected['name'],
            'unit' => $selected['unit'],
            'collection' => $selected['collection'],
            'section' => $selected['section'],
            'dataset_version' => $match['version'],
            'price_version' => $match['price_version'] ?? null,
            'score' => $selected['score'],
            'confidence' => $selected['confidence'],
            'match_reasons' => $selected['match_reasons'],
            'warnings' => array_values(array_unique([
                ...($selected['warnings'] ?? []),
                ...($decision['warnings'] ?? []),
            ])),
            'decision' => $decision,
            'resources_count' => $this->resourcesCount($selected['resources']),
            'priced_resources_count' => $this->pricedResourcesCount($selected['resources']),
            'work_composition' => $this->normalizeComposition($selected['work_composition'] ?? []),
        ];
        $workItem = $this->applyNormativeComposition($workItem, $selected);
        $freshCandidates = array_map(
            fn (array $candidate): array => $this->candidateSummary($candidate, $workItem),
            $match['candidates']
        );
        $workItem['normative_candidates'] = $this->mergeNormativeCandidates(
            $freshCandidates,
            is_array($workItem['normative_candidates'] ?? null) ? $workItem['normative_candidates'] : []
        );
        $workItem['validation_flags'] = array_values(array_unique($flags));

        return $workItem;
    }

    /**
     * @param  array<int, array<string, mixed>>  $resources
     * @param  array<string, mixed>  $selected
     * @param  array<string, mixed>  $version
     * @return array<int, array<string, mixed>>
     */
    private function mapResources(array $resources, string $targetType, float $normQuantity, array $selected, array $version, array $workItem): array
    {
        return array_map(
            function (array $resource, int $index) use ($targetType, $normQuantity, $selected, $version, $workItem): array {
                $quantityPerUnit = $resource['quantity'] !== null ? (float) $resource['quantity'] : 0.0;
                $quantity = round($quantityPerUnit * $normQuantity, 6);
                $unitPrice = (float) ($resource['unit_price'] ?? 0);

                return [
                    'key' => ($workItem['key'] ?? 'work').'-norm-'.$selected['norm_id'].'-'.$targetType.'-'.($index + 1),
                    'name' => $resource['name'] ?? $resource['code'] ?? 'resource',
                    'resource_type' => $targetType,
                    'unit' => $resource['unit'],
                    'price_unit' => $resource['price_unit'] ?? $resource['unit'],
                    'quantity' => $quantity,
                    'quantity_per_unit' => $quantityPerUnit,
                    'quantity_basis' => 'normative_resource',
                    'unit_price' => $unitPrice,
                    'total_price' => round($quantity * $unitPrice, 2),
                    'source' => 'fsnb_2022:'.$version['version_key'],
                    'confidence' => $selected['confidence'],
                    'normative_ref' => [
                        'norm_id' => $selected['norm_id'],
                        'catalog_source' => $selected['catalog_source'] ?? 'estimate_norms',
                        'normative_rate_id' => $selected['normative_rate_id'] ?? null,
                        'norm_code' => $selected['code'],
                        'resource_code' => $resource['code'],
                        'resource_id' => $resource['linked_resource_id'],
                        'norm_resource_id' => $resource['norm_resource_id'] ?? null,
                        'price_id' => $resource['price_id'],
                        'price_source' => $resource['price_source'],
                        'embedded_price' => $resource['embedded_price'] ?? null,
                    ],
                ];
            },
            array_values($resources),
            array_keys(array_values($resources))
        );
    }

    /**
     * @param  array<string, mixed>  $workItem
     * @return array<string, mixed>
     */
    private function markUnmatched(array $workItem): array
    {
        $flags = $workItem['validation_flags'] ?? [];
        $flags[] = 'normative_not_found';
        $flags[] = 'requires_normative_review';
        $flags[] = 'safe_norm_required';
        $flags[] = 'pricing_not_calculated';
        $workItem = $this->clearNonNormativeResources($workItem);
        $workItem['pricing_status'] = 'not_calculated';
        $workItem['pricing_blocker'] = 'normative_not_found';
        $workItem['pricing_blocker_message'] = trans_message('estimate_generation.pricing_not_calculated_safe_norm');
        $workItem['normative_match'] = [
            'status' => 'not_found',
            'message' => trans_message('estimate_generation.normative_manual_selection_required'),
        ];
        $workItem['normative_candidates'] = [];
        $workItem['validation_flags'] = array_values(array_unique($flags));

        return $workItem;
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function candidateSummary(array $candidate, array $workItem): array
    {
        return $this->candidatePresenter->present($candidate, $workItem);
    }

    /**
     * @param  array<int, array<string, mixed>>  $freshCandidates
     * @param  array<int, mixed>  $existingCandidates
     * @return array<int, array<string, mixed>>
     */
    private function mergeNormativeCandidates(array $freshCandidates, array $existingCandidates): array
    {
        $merged = [];
        $seen = [];

        foreach ([...$freshCandidates, ...$existingCandidates] as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $identity = $this->candidateIdentity($candidate);

            if (isset($seen[$identity])) {
                continue;
            }

            $seen[$identity] = true;
            $merged[] = $candidate;
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function candidateIdentity(array $candidate): string
    {
        $normId = $candidate['norm_id'] ?? $candidate['id'] ?? null;

        if ($normId !== null && $normId !== '') {
            return 'id:'.(int) $normId;
        }

        $code = trim((string) ($candidate['code'] ?? $candidate['normative_code'] ?? ''));

        if ($code !== '') {
            return 'code:'.mb_strtolower($code);
        }

        return 'raw:'.hash('sha256', json_encode($candidate, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: serialize($candidate));
    }

    /**
     * @param  array<string, mixed>  $workItem
     * @return array<string, mixed>
     */
    private function clearNonNormativeResources(array $workItem): array
    {
        $workItem['materials'] = [];
        $workItem['labor'] = [];
        $workItem['machinery'] = [];
        $workItem['other_resources'] = [];
        $workItem['work_cost'] = 0;
        $workItem['materials_cost'] = 0;
        $workItem['machinery_cost'] = 0;
        $workItem['labor_cost'] = 0;
        $workItem['total_cost'] = 0;
        $workItem['price_source'] = null;

        return $workItem;
    }

    /**
     * @param  array<int, string>  $flags
     * @return array<int, string>
     */
    private function acceptedFlags(array $flags): array
    {
        return array_values(array_diff(array_values(array_unique($flags)), [
            'normative_required',
            'normative_candidate_only',
            'normative_not_found',
            'normative_match_low_confidence',
            'requires_normative_review',
            'safe_normative_analog',
            'missing_price',
            'missing_resources',
            'safe_norm_required',
            'normative_price_required',
            'pricing_not_calculated',
        ]));
    }

    /**
     * @param  array<int, string>  $warnings
     * @return array<int, string>
     */
    private function hardWarningFlags(array $warnings): array
    {
        return array_values(array_intersect(array_map('strval', $warnings), [
            'unit_mismatch',
            'scope_mismatch',
            'norm_without_resources',
            'norm_without_prices',
            'norm_without_resource_prices',
            'norm_with_unpriced_resources',
        ]));
    }

    /**
     * @param  array<int, string>  $warnings
     */
    private function pricingBlocker(array $warnings): string
    {
        $warnings = array_map('strval', $warnings);

        if (in_array('unit_mismatch', $warnings, true)) {
            return 'unit_mismatch';
        }

        if (in_array('scope_mismatch', $warnings, true)) {
            return 'scope_mismatch';
        }

        if (array_intersect($warnings, ['norm_without_resources', 'norm_without_prices', 'norm_without_resource_prices']) !== []) {
            return 'normative_resources_or_prices_missing';
        }

        if (in_array('norm_with_unpriced_resources', $warnings, true)) {
            return 'norm_with_unpriced_resources';
        }

        return 'safe_norm_required';
    }

    /**
     * @param  array<int, string>  $warnings
     */
    private function requiresNormativePriceSelection(array $warnings): bool
    {
        return array_intersect(array_map('strval', $warnings), [
            'norm_without_prices',
            'norm_without_resource_prices',
            'norm_with_unpriced_resources',
            'prices_missing',
        ]) !== [];
    }

    private function pricingBlockerMessageKey(string $pricingBlocker): string
    {
        return match ($pricingBlocker) {
            'norm_with_unpriced_resources' => 'estimate_generation.pricing_not_calculated_partial_resource_prices',
            default => 'estimate_generation.pricing_not_calculated_safe_norm',
        };
    }

    /**
     * @param  array<string, mixed>  $workItem
     * @param  array<string, mixed>  $selected
     * @return array<string, mixed>
     */
    private function applyNormativeComposition(array $workItem, array $selected): array
    {
        $composition = $this->normalizeComposition($selected['work_composition'] ?? []);

        if ($composition === []) {
            return $workItem;
        }

        $workItem['work_composition'] = $composition;
        $workItem['metadata'] = [
            ...($workItem['metadata'] ?? []),
            'work_composition' => $composition,
            'composition_source' => 'fsnb_norm',
        ];

        return $workItem;
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
}
