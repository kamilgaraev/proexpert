<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\EstimateNormativeMatcher;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeMatchDecisionService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeCandidatePresenter;
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
    ) {}

    public function enrich(array $workItems, array $context = []): array
    {
        $progressCallback = is_callable($context['progress_callback'] ?? null) ? $context['progress_callback'] : null;
        $total = count($workItems);
        $matchCache = [];

        foreach ($workItems as $index => &$workItem) {
            if (($workItem['skip_normative_matching'] ?? false) === true || !$this->isPricedItem($workItem)) {
                $processed = $index + 1;

                if ($progressCallback !== null && ($processed % self::PROGRESS_STEP === 0 || $processed === $total)) {
                    $progressCallback($processed, $total);
                }

                continue;
            }

            $workItemForMatching = $this->withWorkIntent($this->workItemForMatching($workItem), $context);
            $workItem['work_intent'] = $workItemForMatching['work_intent'];
            $cacheKey = $this->matchCacheKey($workItem, $context);
            $match = array_key_exists($cacheKey, $matchCache)
                ? $matchCache[$cacheKey]
                : $matchCache[$cacheKey] = $this->normativeMatcher->matchWorkItem($workItemForMatching, $context);

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
     * @param array<string, mixed> $workItem
     * @param array<string, mixed> $match
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function applySelectedNormativeMatch(array $workItem, array $match, array $context = []): array
    {
        $workItem = $this->withWorkIntent($workItem, $context);

        return $this->applyDecidedNormativeMatch($workItem, $match, true);
    }

    /**
     * @param array<string, mixed> $workItem
     */
    private function isPricedItem(array $workItem): bool
    {
        return !in_array((string) ($workItem['item_type'] ?? 'priced_work'), ['operation', 'resource_note', 'review_note'], true);
    }

    /**
     * @param array<string, mixed> $workItem
     * @param array<string, mixed> $context
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
     * @param array<string, mixed> $workItem
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
     * @param array<string, mixed> $workItem
     * @param array<string, mixed> $context
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
     * @param array<string, mixed> $workItem
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
     * @param array<string, mixed> $workItem
     * @param array<string, mixed> $match
     * @return array<string, mixed>
     */
    private function applyNormativeMatch(array $workItem, array $match): array
    {
        return $this->applyDecidedNormativeMatch($workItem, $match, false);
    }

    /**
     * @param array<string, mixed> $workItem
     * @param array<string, mixed> $match
     * @return array<string, mixed>
     */
    private function applyDecidedNormativeMatch(array $workItem, array $match, bool $selectedByUser): array
    {
        $selected = $match['selected'];
        $decision = $this->matchDecisionService->decide($selected, $workItem);

        if (!$decision->canUseForPricing) {
            return $this->applyCandidateOnlyMatch($workItem, $match, $decision->toArray(), $selectedByUser);
        }

        $decisionPayload = $decision->toArray();
        $workItem = $this->applyNormativeResources($workItem, $match, $selectedByUser, $decisionPayload);
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
        }

        $workItem['validation_flags'] = array_values(array_unique($flags));

        return $workItem;
    }

    /**
     * @param array<string, mixed> $workItem
     * @param array<string, mixed> $match
     * @return array<string, mixed>
     */
    private function applyNormativeResources(array $workItem, array $match, bool $selectedByUser = false, ?array $decision = null): array
    {
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
        $warnings = array_values(array_unique([
            ...($selected['warnings'] ?? []),
            ...($decision['warnings'] ?? []),
        ]));

        $workItem['normative_match'] = [
            'status' => 'matched',
            'selected_by_user' => $selectedByUser,
            'selected_candidate_key' => $selected['key'],
            'norm_id' => $selected['norm_id'],
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
            'work_composition' => $this->normalizeComposition($selected['work_composition'] ?? []),
        ];
        $workItem = $this->applyNormativeComposition($workItem, $selected);
        $workItem['normative_candidates'] = array_map(
            fn (array $candidate): array => $this->candidateSummary($candidate),
            $match['candidates']
        );
        $workItem['confidence'] = round(((float) ($workItem['confidence'] ?? 0.5) + (float) $selected['confidence']) / 2, 4);
        $workItem['validation_flags'] = $this->acceptedFlags($workItem['validation_flags'] ?? []);

        return $workItem;
    }

    /**
     * @param array<string, mixed> $workItem
     * @param array<string, mixed> $match
     * @param array<string, mixed> $decision
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

        $workItem['pricing_status'] = 'not_calculated';
        $workItem['pricing_blocker'] = $this->pricingBlocker($decision['warnings'] ?? []);
        $workItem['pricing_blocker_message'] = trans_message('estimate_generation.pricing_not_calculated_safe_norm');

        $workItem['normative_match'] = [
            'status' => $decision['status'],
            'selected_by_user' => $selectedByUser,
            'selected_candidate_key' => $selected['key'],
            'norm_id' => $selected['norm_id'],
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
        $workItem['normative_candidates'] = array_map(
            fn (array $candidate): array => $this->candidateSummary($candidate),
            $match['candidates']
        );
        $workItem['validation_flags'] = array_values(array_unique($flags));

        return $workItem;
    }

    /**
     * @param array<int, array<string, mixed>> $resources
     * @param array<string, mixed> $selected
     * @param array<string, mixed> $version
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
                    'key' => ($workItem['key'] ?? 'work') . '-norm-' . $selected['norm_id'] . '-' . $targetType . '-' . ($index + 1),
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
     * @param array<string, mixed> $candidate
     * @return array<string, mixed>
     */
    private function candidateSummary(array $candidate): array
    {
        return $this->candidatePresenter->present($candidate);
    }

    /**
     * @param array<string, mixed> $workItem
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
     * @param array<int, string> $flags
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
            'pricing_not_calculated',
        ]));
    }

    /**
     * @param array<int, string> $warnings
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
        ]));
    }

    /**
     * @param array<int, string> $warnings
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

        return 'safe_norm_required';
    }

    /**
     * @param array<string, mixed> $workItem
     * @param array<string, mixed> $selected
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
     * @param mixed $composition
     * @return array<int, string>
     */
    private function normalizeComposition(mixed $composition): array
    {
        if (!is_array($composition)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $item): string => trim((string) $item), $composition),
            static fn (string $item): bool => $item !== ''
        ));
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
