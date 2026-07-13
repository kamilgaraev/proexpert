<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\ReviewSummarySnapshot;

class EstimateValidationService
{
    private const NORMATIVE_PRICE_REQUIRED_MARKERS = [
        'normative_price_required',
        'normative_prices_missing',
        'norm_without_prices',
        'norm_without_resource_prices',
        'norm_with_unpriced_resources',
        'prices_missing',
    ];

    public function __construct(
        private readonly EstimateGenerationNoAirWorkItemPolicy $noAirWorkItemPolicy = new EstimateGenerationNoAirWorkItemPolicy,
        private readonly ?EstimateGenerationReviewItemService $reviewItemService = null,
    ) {}

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    public function validate(array $draft): array
    {
        $projectFlags = [];
        $confidenceSum = 0.0;
        $confidenceCount = 0;
        $totalCost = 0.0;
        $workItemsCount = 0;
        $pricedWorkItemsCount = 0;
        $operationWorkItemsCount = 0;
        $zeroPriceWorkItemsCount = 0;
        $normativeMatchedWorkItemsCount = 0;
        $normativeReviewPricedWorkItemsCount = 0;
        $normativeCandidateWorkItemsCount = 0;
        $normativeCandidateOnlyWorkItemsCount = 0;
        $normativeRejectedWorkItemsCount = 0;
        $normativeNotFoundWorkItemsCount = 0;
        $normativeUnitMismatchWorkItemsCount = 0;
        $normativeScopeMismatchWorkItemsCount = 0;
        $marketEstimateWorkItemsCount = 0;
        $safeNormRequiredWorkItemsCount = 0;
        $normativePriceRequiredWorkItemsCount = 0;
        $normativeCodeRequiredWorkItemsCount = 0;
        $notCalculatedWorkItemsCount = 0;
        $duplicateWorkItemsCount = 0;
        $quantityReviewWorkItemsCount = 0;

        foreach ($draft['local_estimates'] as $localIndex => $localEstimate) {
            $localFlags = [];
            if ($localEstimate['source_refs'] === []) {
                $localFlags[] = 'weak_source_reference';
            }

            foreach ($localEstimate['sections'] as $sectionIndex => $section) {
                $sectionFlags = [];
                $seenWorkItemSignatures = [];
                $sectionDuplicateIndexes = [];
                if (mb_strtolower($section['title']) === 'прочее') {
                    $sectionFlags[] = 'generic_section_name';
                }

                $sectionTotal = 0.0;
                foreach ($section['work_items'] as $workIndex => $workItem) {
                    $workItemsCount++;
                    $flags = $workItem['validation_flags'] ?? [];
                    $total = (float) ($workItem['total_cost'] ?? 0);
                    $itemType = (string) ($workItem['item_type'] ?? 'priced_work');
                    $isQuantityReviewItem = $itemType === 'quantity_review';
                    $isPricedItem = ! $isQuantityReviewItem
                        && ! in_array($itemType, ['operation', 'resource_note', 'review_note'], true);
                    $hasResources = ($workItem['materials'] ?? []) !== []
                        || ($workItem['labor'] ?? []) !== []
                        || ($workItem['machinery'] ?? []) !== [];

                    if ($isPricedItem && $this->noAirWorkItemPolicy->requiresReview($workItem)) {
                        $workItem = $this->noAirWorkItemPolicy->markRequiresReview($workItem);
                        $flags = $workItem['validation_flags'];
                        $total = 0.0;
                        $hasResources = false;
                    }

                    if ($isQuantityReviewItem) {
                        $quantityReviewWorkItemsCount++;
                        $flags[] = 'quantity_review_required';
                        $workItem['pricing_status'] = 'not_applicable';
                        $workItem['pricing_blocker'] = $workItem['pricing_blocker'] ?? 'quantity_review_required';
                    } elseif (! $isPricedItem) {
                        $operationWorkItemsCount++;
                    }

                    if ($isPricedItem) {
                        $duplicateSignature = $this->duplicateSignature($workItem);

                        if ($duplicateSignature !== null && isset($seenWorkItemSignatures[$duplicateSignature])) {
                            $firstDuplicateIndex = $seenWorkItemSignatures[$duplicateSignature];
                            $sectionDuplicateIndexes[$firstDuplicateIndex] = true;
                            $sectionDuplicateIndexes[$workIndex] = true;
                            $flags[] = 'possible_duplicate_work_item';
                            $flags[] = 'requires_duplicate_review';
                            $this->appendWorkItemFlags(
                                $draft,
                                $localIndex,
                                $sectionIndex,
                                $firstDuplicateIndex,
                                ['possible_duplicate_work_item', 'requires_duplicate_review']
                            );
                        } elseif ($duplicateSignature !== null) {
                            $seenWorkItemSignatures[$duplicateSignature] = $workIndex;
                        }
                    }

                    if (($workItem['quantity_basis'] ?? null) === null || $workItem['quantity_basis'] === '') {
                        $flags[] = 'missing_quantity_basis';
                    }

                    $priceMissing = $isPricedItem && $total <= 0;
                    $resourcesMissing = $isPricedItem && ! $hasResources;

                    if ($priceMissing) {
                        $flags[] = 'missing_price';
                        $zeroPriceWorkItemsCount++;
                    }

                    if ($resourcesMissing) {
                        $flags[] = 'missing_resources';
                    }

                    if ($isPricedItem && (float) ($workItem['quantity'] ?? 0) <= 0) {
                        $flags[] = 'suspicious_quantity';
                    }

                    if ($isPricedItem && (float) ($workItem['confidence'] ?? 0) < 0.6) {
                        $flags[] = 'low_confidence';
                    }

                    $normativeMatch = is_array($workItem['normative_match'] ?? null) ? $workItem['normative_match'] : [];
                    $normativeStatus = $normativeMatch['status'] ?? null;
                    $normativeDecision = is_array($normativeMatch['decision'] ?? null) ? $normativeMatch['decision'] : [];
                    $normativeDecisionStatus = (string) ($normativeDecision['status'] ?? '');
                    $normativeWarnings = $this->normativeWarnings($normativeMatch);
                    $normativePriceRequired = $this->normativePriceRequired($normativeWarnings, $flags, $workItem);
                    $normativeCodeRequired = $this->normativeCodeRequired($workItem, $flags);
                    $safeNormFlags = $normativeCodeRequired ? [...$flags, 'normative_code_required'] : $flags;
                    $safeNormRequired = $this->safeNormRequired($normativeStatus, $normativeDecisionStatus, $normativeWarnings, $safeNormFlags);

                    if ($isPricedItem && $normativeCodeRequired) {
                        $flags[] = 'normative_code_required';
                        $normativeCodeRequiredWorkItemsCount++;
                    }

                    if ($isPricedItem && $normativePriceRequired) {
                        $flags[] = 'normative_price_required';
                        $normativePriceRequiredWorkItemsCount++;
                    }

                    if ($isPricedItem && $safeNormRequired) {
                        $flags[] = 'safe_norm_required';
                        $flags[] = 'pricing_not_calculated';
                        $safeNormRequiredWorkItemsCount++;
                    }

                    if ($isPricedItem && ($priceMissing || $resourcesMissing || $safeNormRequired || $normativeCodeRequired)) {
                        $workItem['pricing_status'] = 'not_calculated';
                        $workItem['pricing_blocker'] = $this->pricingBlocker(
                            $workItem['pricing_blocker'] ?? null,
                            $safeNormRequired,
                            $normativePriceRequired,
                            $normativeCodeRequired,
                            $normativeWarnings
                        );
                        $flags[] = 'pricing_not_calculated';
                    }

                    if (in_array('unit_mismatch', $normativeWarnings, true)) {
                        $normativeUnitMismatchWorkItemsCount++;
                    }

                    if (in_array('scope_mismatch', $normativeWarnings, true)) {
                        $normativeScopeMismatchWorkItemsCount++;
                    }

                    if ($normativeStatus === 'matched') {
                        if ($normativeDecisionStatus === 'review_priced') {
                            $normativeReviewPricedWorkItemsCount++;
                        } else {
                            $normativeMatchedWorkItemsCount++;
                        }
                    } elseif ($normativeStatus === 'candidate') {
                        $normativeCandidateWorkItemsCount++;
                        $normativeCandidateOnlyWorkItemsCount++;
                    } elseif ($normativeStatus === 'rejected') {
                        $normativeRejectedWorkItemsCount++;
                    } elseif ($normativeStatus === 'not_found') {
                        $normativeNotFoundWorkItemsCount++;
                    }

                    if ($isPricedItem && (in_array('market_price_used', $flags, true) || ($workItem['price_source'] ?? null) === 'market_estimate')) {
                        $marketEstimateWorkItemsCount++;
                    }

                    if ($isPricedItem && (string) ($workItem['pricing_status'] ?? '') === 'not_calculated') {
                        $notCalculatedWorkItemsCount++;
                    } elseif ($isPricedItem) {
                        $pricedWorkItemsCount++;
                    }

                    $flags = array_values(array_unique($flags));
                    $workItem['validation_flags'] = $flags;
                    $workItem['pricing_status'] = $workItem['pricing_status'] ?? null;
                    $workItem['pricing_blocker'] = $workItem['pricing_blocker'] ?? null;
                    $draft['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'][$workIndex] = [
                        ...$draft['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'][$workIndex],
                        ...$workItem,
                    ];
                    $sectionTotal += $total;
                    $confidenceSum += (float) ($workItem['confidence'] ?? 0);
                    $confidenceCount++;
                    $sectionFlags = array_values(array_unique([...$sectionFlags, ...$flags]));
                }

                $duplicateWorkItemsCount += count($sectionDuplicateIndexes);

                $draft['local_estimates'][$localIndex]['sections'][$sectionIndex]['validation_flags'] = $sectionFlags;
                $draft['local_estimates'][$localIndex]['sections'][$sectionIndex]['section_totals'] = [
                    'total_cost' => round($sectionTotal, 2),
                    'items_count' => count($section['work_items']),
                ];

                $totalCost += $sectionTotal;
                $localFlags = array_values(array_unique([...$localFlags, ...$sectionFlags]));
            }

            $draft['local_estimates'][$localIndex]['validation_flags'] = $localFlags;
            $draft['local_estimates'][$localIndex]['totals'] = [
                'total_cost' => round(array_sum(array_map(
                    static fn (array $section): float => (float) ($section['section_totals']['total_cost'] ?? 0),
                    $draft['local_estimates'][$localIndex]['sections']
                )), 2),
                'sections_count' => count($draft['local_estimates'][$localIndex]['sections']),
            ];

            $projectFlags = array_values(array_unique([...$projectFlags, ...$localFlags]));
        }

        $contingency = $this->contingency($draft, $totalCost);
        $draft['totals'] = [
            'total_cost' => round($totalCost + $contingency['amount'], 2),
            'base_total_cost' => round($totalCost, 2),
            'contingency' => $contingency,
            'local_estimates_count' => count($draft['local_estimates']),
            'work_items_count' => $workItemsCount,
        ];
        $draft['confidence'] = [
            'average' => $confidenceCount > 0 ? round($confidenceSum / $confidenceCount, 4) : 0,
        ];
        $draft['problem_flags'] = $projectFlags;
        $draft['quality_summary'] = $this->qualitySummary(
            $workItemsCount,
            $pricedWorkItemsCount,
            $operationWorkItemsCount,
            $zeroPriceWorkItemsCount,
            $normativeMatchedWorkItemsCount,
            $normativeReviewPricedWorkItemsCount,
            $normativeCandidateWorkItemsCount,
            $normativeCandidateOnlyWorkItemsCount,
            $normativeRejectedWorkItemsCount,
            $normativeNotFoundWorkItemsCount,
            $normativeUnitMismatchWorkItemsCount,
            $normativeScopeMismatchWorkItemsCount,
            $marketEstimateWorkItemsCount,
            $safeNormRequiredWorkItemsCount,
            $normativePriceRequiredWorkItemsCount,
            $normativeCodeRequiredWorkItemsCount,
            $notCalculatedWorkItemsCount,
            $duplicateWorkItemsCount,
            $quantityReviewWorkItemsCount,
            $projectFlags
        );
        $reviewItemService = $this->reviewItemService ?? new EstimateGenerationReviewItemService(
            new EstimateGenerationPackagePresenter,
        );
        $reviewSummary = $reviewItemService->summaryForDraft($draft);
        $draft['quality_summary']['review_queue_items'] = $reviewItemService->projectionForDraft($draft);
        $draft['quality_summary']['content_version'] = ReviewSummarySnapshot::contentVersion($draft);
        $draft['quality_summary']['review_items'] = ReviewSummarySnapshot::create($draft, $reviewSummary);

        return $draft;
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array{percent: float, amount: float}
     */
    private function contingency(array $draft, float $totalCost): array
    {
        $percent = (float) ($draft['contingency_percent'] ?? 0);
        if ($percent <= 0) {
            return ['percent' => 0.0, 'amount' => 0.0];
        }

        return [
            'percent' => $percent,
            'amount' => round($totalCost * $percent / 100, 2),
        ];
    }

    /**
     * @param  array<int, string>  $projectFlags
     * @return array<string, mixed>
     */
    private function qualitySummary(
        int $totalWorkItems,
        int $pricedWorkItems,
        int $operationWorkItems,
        int $zeroPriceWorkItems,
        int $normativeMatchedWorkItems,
        int $normativeReviewPricedWorkItems,
        int $normativeCandidateWorkItems,
        int $normativeCandidateOnlyWorkItems,
        int $normativeRejectedWorkItems,
        int $normativeNotFoundWorkItems,
        int $normativeUnitMismatchWorkItems,
        int $normativeScopeMismatchWorkItems,
        int $marketEstimateWorkItems,
        int $safeNormRequiredWorkItems,
        int $normativePriceRequiredWorkItems,
        int $normativeCodeRequiredWorkItems,
        int $notCalculatedWorkItems,
        int $duplicateWorkItems,
        int $quantityReviewWorkItems,
        array $projectFlags
    ): array {
        $requiresNormativeReview = $normativeReviewPricedWorkItems
            + $normativeCandidateWorkItems
            + $normativeRejectedWorkItems
            + $normativeNotFoundWorkItems;
        $pricedDenominator = max($totalWorkItems - $operationWorkItems - $quantityReviewWorkItems, 0);
        $criticalFlags = array_values(array_intersect($projectFlags, [
            'missing_price',
            'missing_resources',
            'normative_code_required',
            'normative_price_required',
            'regional_context_missing',
        ]));
        $reviewFlags = array_values(array_intersect($projectFlags, [
            'requires_normative_review',
            'normative_candidate_only',
            'normative_match_low_confidence',
            'low_confidence',
            'possible_duplicate_work_item',
            'requires_duplicate_review',
            'quantity_review_required',
        ]));
        $warningFlags = array_values(array_diff($projectFlags, $criticalFlags));
        $status = 'ready';

        if ($pricedDenominator === 0) {
            $status = $quantityReviewWorkItems > 0 ? 'review_required' : 'critical';
        } elseif ($zeroPriceWorkItems === $pricedDenominator || $pricedWorkItems === 0) {
            $status = 'critical';
        } elseif (
            $zeroPriceWorkItems > 0
            || $marketEstimateWorkItems > 0
            || $duplicateWorkItems > 0
            || $quantityReviewWorkItems > 0
            || $criticalFlags !== []
            || $requiresNormativeReview > 0
            || $reviewFlags !== []
        ) {
            $status = 'review_required';
        }

        return [
            'status' => $status,
            'total_work_items' => $totalWorkItems,
            'priced_work_items' => $pricedWorkItems,
            'operation_work_items' => $operationWorkItems,
            'zero_price_work_items' => $zeroPriceWorkItems,
            'not_calculated_work_items' => $notCalculatedWorkItems,
            'safe_norm_required_work_items' => $safeNormRequiredWorkItems,
            'normative_price_required_work_items' => $normativePriceRequiredWorkItems,
            'normative_code_required_work_items' => $normativeCodeRequiredWorkItems,
            'duplicate_work_items' => $duplicateWorkItems,
            'quantity_review_work_items' => $quantityReviewWorkItems,
            'normative_matched_work_items' => $normativeMatchedWorkItems,
            'market_estimate_work_items' => $marketEstimateWorkItems,
            'normative_items' => [
                'accepted' => $normativeMatchedWorkItems,
                'review_priced' => $normativeReviewPricedWorkItems,
                'candidate' => $normativeCandidateWorkItems,
                'candidate_only' => $normativeCandidateOnlyWorkItems,
                'rejected' => $normativeRejectedWorkItems,
                'not_found' => $normativeNotFoundWorkItems,
                'unit_mismatch' => $normativeUnitMismatchWorkItems,
                'scope_mismatch' => $normativeScopeMismatchWorkItems,
                'safe_norm_required' => $safeNormRequiredWorkItems,
                'price_required' => $normativePriceRequiredWorkItems,
                'code_required' => $normativeCodeRequiredWorkItems,
                'requires_review' => $requiresNormativeReview,
            ],
            'critical_flags' => $criticalFlags,
            'warning_flags' => $warningFlags,
        ];
    }

    /**
     * @param  array<string, mixed>  $normativeMatch
     * @return array<int, string>
     */
    private function normativeWarnings(array $normativeMatch): array
    {
        $decision = is_array($normativeMatch['decision'] ?? null) ? $normativeMatch['decision'] : [];

        return array_values(array_unique([
            ...array_map('strval', $normativeMatch['warnings'] ?? []),
            ...array_map('strval', $decision['warnings'] ?? []),
        ]));
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  array<int, string>  $flags
     */
    private function appendWorkItemFlags(array &$draft, int $localIndex, int $sectionIndex, int $workIndex, array $flags): void
    {
        $existingFlags = $draft['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'][$workIndex]['validation_flags'] ?? [];

        if (! is_array($existingFlags)) {
            $existingFlags = [];
        }

        $draft['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'][$workIndex]['validation_flags'] = array_values(array_unique([
            ...array_map('strval', $existingFlags),
            ...$flags,
        ]));
    }

    /**
     * @param  array<string, mixed>  $workItem
     */
    private function duplicateSignature(array $workItem): ?string
    {
        $name = $this->normalizeSignaturePart((string) ($workItem['normative_search_text'] ?? $workItem['name'] ?? ''));
        $unit = $this->normalizeSignaturePart((string) ($workItem['unit'] ?? ''));
        $quantity = round((float) ($workItem['quantity'] ?? 0), 4);

        if ($name === '' || $unit === '' || $quantity <= 0) {
            return null;
        }

        $normativeIdentity = $this->normalizeSignaturePart((string) (
            $workItem['normative_rate_code']
            ?? $workItem['normative_search_key']
            ?? $workItem['quantity_formula']
            ?? ''
        ));

        return hash('sha256', implode('|', [
            $name,
            $unit,
            (string) $quantity,
            $normativeIdentity,
        ]));
    }

    private function normalizeSignaturePart(string $value): string
    {
        $value = mb_strtolower(trim($value));

        return preg_replace('/\s+/u', ' ', $value) ?? $value;
    }

    /**
     * @param  array<int, string>  $warnings
     * @param  array<int, string>  $flags
     */
    private function safeNormRequired(mixed $normativeStatus, string $decisionStatus, array $warnings, array $flags): bool
    {
        if (
            in_array('safe_norm_required', $flags, true)
            || in_array('pricing_not_calculated', $flags, true)
            || in_array('normative_code_required', $flags, true)
            || in_array('normative_price_required', $flags, true)
        ) {
            return true;
        }

        if (in_array((string) $normativeStatus, ['candidate', 'rejected', 'not_found', 'unmatched', 'low_confidence'], true)) {
            return true;
        }

        if ($decisionStatus !== '' && $decisionStatus !== 'accepted' && $decisionStatus !== 'review_priced') {
            return true;
        }

        return array_intersect($warnings, [
            'unit_mismatch',
            'scope_mismatch',
            'norm_without_resources',
            'norm_without_prices',
            'norm_without_resource_prices',
            'norm_with_unpriced_resources',
        ]) !== [];
    }

    /**
     * @param  array<int, string>  $warnings
     * @param  array<int, string>  $flags
     * @param  array<string, mixed>  $workItem
     */
    private function normativePriceRequired(array $warnings, array $flags, array $workItem): bool
    {
        $markers = [
            ...$warnings,
            ...array_map('strval', $flags),
            (string) ($workItem['pricing_blocker'] ?? ''),
        ];

        return array_intersect($markers, self::NORMATIVE_PRICE_REQUIRED_MARKERS) !== [];
    }

    /**
     * @param  array<string, mixed>  $workItem
     * @param  array<int, string>  $flags
     */
    private function normativeCodeRequired(array $workItem, array $flags): bool
    {
        if ($this->normativeRateCode($workItem) !== null) {
            return false;
        }

        $metadata = is_array($workItem['metadata'] ?? null) ? $workItem['metadata'] : [];
        $sourceRefs = is_array($workItem['source_refs'] ?? null) ? $workItem['source_refs'] : [];

        if (
            in_array('normative_code_required', $flags, true)
            || in_array('normative_code_expected', $flags, true)
            || (bool) ($metadata['normative_code_required'] ?? false)
            || (bool) ($metadata['normative_code_expected'] ?? false)
            || (string) ($metadata['generation_source'] ?? '') === 'project_document_normative_reference'
            || $this->hasProjectDocumentNormReference($sourceRefs)
        ) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $workItem
     */
    private function normativeRateCode(array $workItem): ?string
    {
        $code = trim((string) ($workItem['normative_rate_code'] ?? data_get($workItem, 'normative_match.code', '')));

        return $code !== '' ? $code : null;
    }

    /**
     * @param  array<int, mixed>  $sourceRefs
     */
    private function hasProjectDocumentNormReference(array $sourceRefs): bool
    {
        foreach ($sourceRefs as $sourceRef) {
            if (! is_array($sourceRef)) {
                continue;
            }

            if ((string) ($sourceRef['type'] ?? '') === 'project_document_norm_reference') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $warnings
     */
    private function pricingBlocker(
        mixed $currentBlocker,
        bool $safeNormRequired,
        bool $normativePriceRequired,
        bool $normativeCodeRequired,
        array $warnings
    ): string {
        $currentBlocker = trim((string) $currentBlocker);

        if (
            $normativeCodeRequired
            && ($currentBlocker === '' || in_array($currentBlocker, ['normative_required', 'safe_norm_required', 'normative_resources_or_prices_missing'], true))
        ) {
            return 'normative_code_required';
        }

        if (
            $normativePriceRequired
            && ($currentBlocker === '' || in_array($currentBlocker, ['normative_required', 'safe_norm_required', 'normative_resources_or_prices_missing'], true))
        ) {
            if (in_array('norm_with_unpriced_resources', $warnings, true) || $currentBlocker === 'norm_with_unpriced_resources') {
                return 'norm_with_unpriced_resources';
            }

            return 'normative_resources_or_prices_missing';
        }

        if ($currentBlocker !== '') {
            return $currentBlocker;
        }

        return $safeNormRequired ? 'normative_required' : 'normative_resources_or_prices_missing';
    }
}
