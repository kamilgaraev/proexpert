<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackageItem;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use Illuminate\Support\Collection;

final class EstimateGenerationReviewItemService
{
    private const SEVERITY_BLOCKING = 'blocking';

    private const SEVERITY_WARNING = 'warning';

    private const SEVERITY_OPTIONAL = 'optional';

    private const ACTION_CONFIRM_QUANTITY = 'confirm_quantity';

    private const ACTION_SELECT_NORM = 'select_norm';

    private const ACTION_REVIEW_NORM = 'review_norm';

    private const ACTION_RESOLVE_DUPLICATE = 'resolve_duplicate';

    private const ACTION_RESOLVE_GENERIC_WORK = 'resolve_generic_work';

    private const ACTION_CHECK_PRICE = 'check_price';

    private const NORMATIVE_REVIEW_STATUSES = [
        'candidate',
        'rejected',
        'not_found',
        'unmatched',
        'low_confidence',
    ];

    private const NORMATIVE_REVIEW_FLAGS = [
        EstimateGenerationNoAirWorkItemPolicy::FLAG,
        EstimateGenerationNoAirWorkItemPolicy::NO_AIR_FLAG,
        'safe_norm_required',
        'normative_code_required',
        'normative_candidate_only',
        'normative_not_found',
        'normative_match_low_confidence',
        'unit_mismatch',
        'scope_mismatch',
        'norm_without_resources',
        'norm_without_prices',
        'norm_without_resource_prices',
        'norm_with_unpriced_resources',
    ];

    private const PRICING_FLAGS = [
        EstimateGenerationNoAirWorkItemPolicy::FLAG,
        EstimateGenerationNoAirWorkItemPolicy::NO_AIR_FLAG,
        'normative_price_required',
        'normative_code_required',
        'pricing_not_calculated',
        'missing_price',
        'missing_resources',
        'resources_missing',
        'prices_missing',
    ];

    private const NORMATIVE_CODE_FLAGS = [
        'normative_code_required',
        'normative_code_expected',
    ];

    private const NORMATIVE_PRICE_FLAGS = [
        'normative_price_required',
        'normative_prices_missing',
        'norm_without_prices',
        'norm_without_resource_prices',
        'norm_with_unpriced_resources',
        'prices_missing',
    ];

    public function __construct(
        private readonly EstimateGenerationPackagePresenter $packagePresenter,
        private readonly EstimateGenerationNoAirWorkItemPolicy $noAirWorkItemPolicy = new EstimateGenerationNoAirWorkItemPolicy,
    ) {}

    /**
     * @return array{summary: array<string, int>, items: array<int, array<string, mixed>>}
     */
    public function forSession(EstimateGenerationSession $session, array $filters = []): array
    {
        $draft = is_array($session->draft_payload) ? $session->draft_payload : [];
        $items = $this->draftReviewItems($draft);
        $items = $this->appendPackageReviewItems($session, $items);

        usort($items, static function (array $left, array $right): int {
            $severityRank = [self::SEVERITY_BLOCKING => 0, self::SEVERITY_WARNING => 1, self::SEVERITY_OPTIONAL => 2];

            return ($severityRank[$left['severity']] ?? 99) <=> ($severityRank[$right['severity']] ?? 99)
                ?: strnatcasecmp((string) $left['local_estimate_title'], (string) $right['local_estimate_title'])
                ?: strnatcasecmp((string) $left['section_title'], (string) $right['section_title'])
                ?: strnatcasecmp((string) data_get($left, 'work_item.name', ''), (string) data_get($right, 'work_item.name', ''));
        });

        $items = $this->filterItems($items, $filters);

        return [
            'summary' => $this->summary($items),
            'items' => $items,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    private function filterItems(array $items, array $filters): array
    {
        $severity = is_string($filters['severity'] ?? null) ? $filters['severity'] : null;
        $requiredAction = is_string($filters['required_action'] ?? null) ? $filters['required_action'] : null;
        $search = is_string($filters['search'] ?? null) ? trim(mb_strtolower($filters['search'])) : '';

        return array_values(array_filter($items, static function (array $item) use ($severity, $requiredAction, $search): bool {
            if ($severity !== null && ($item['severity'] ?? null) !== $severity) {
                return false;
            }
            if ($requiredAction !== null && ($item['required_action'] ?? null) !== $requiredAction) {
                return false;
            }
            if ($search === '') {
                return true;
            }

            $haystack = mb_strtolower(implode(' ', [
                (string) ($item['local_estimate_title'] ?? ''),
                (string) ($item['section_title'] ?? ''),
                (string) ($item['work_item_key'] ?? ''),
                (string) data_get($item, 'work_item.name', ''),
            ]));

            return str_contains($haystack, $search);
        }));
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, int>
     */
    public function summaryForDraft(array $draft): array
    {
        return $this->summary($this->draftReviewItems($draft));
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<int, array<string, mixed>>
     */
    private function draftReviewItems(array $draft): array
    {
        $items = [];

        foreach ($draft['local_estimates'] ?? [] as $localEstimate) {
            if (! is_array($localEstimate)) {
                continue;
            }

            foreach ($localEstimate['sections'] ?? [] as $section) {
                if (! is_array($section)) {
                    continue;
                }

                foreach ($section['work_items'] ?? [] as $workItem) {
                    if (! is_array($workItem)) {
                        continue;
                    }

                    $reviewItem = $this->reviewItem($localEstimate, $section, $workItem);

                    if ($reviewItem !== null) {
                        $items[] = $reviewItem;
                    }
                }
            }
        }

        return $items;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function appendPackageReviewItems(EstimateGenerationSession $session, array $items): array
    {
        $itemsByKey = [];

        foreach ($items as $item) {
            $itemsByKey[$this->dedupeKey($item)] = $item;
        }

        foreach ($this->sessionPackages($session) as $package) {
            foreach ($package->items as $packageItem) {
                $workItem = $this->packageWorkItem($packageItem);
                $reviewItem = $this->reviewItem(
                    [
                        'key' => (string) $package->key,
                        'title' => (string) $package->title,
                        'scope_type' => (string) $package->scope_type,
                        'source_refs' => $package->source_refs ?? [],
                    ],
                    [
                        'key' => (string) $package->key.':review',
                        'title' => (string) $package->title,
                        'source_refs' => $package->source_refs ?? [],
                    ],
                    $workItem
                );

                if ($reviewItem === null) {
                    continue;
                }

                $itemsByKey[$this->dedupeKey($reviewItem)] ??= $reviewItem;
            }
        }

        return array_values($itemsByKey);
    }

    /**
     * @return Collection<int, \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackage>
     */
    private function sessionPackages(EstimateGenerationSession $session): Collection
    {
        if ($session->relationLoaded('packages')) {
            $packages = $session->getRelation('packages');

            return $packages instanceof Collection ? $packages : collect();
        }

        if (! $session->exists) {
            return collect();
        }

        return $session->packages()
            ->with('items')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function packageWorkItem(EstimateGenerationPackageItem $packageItem): array
    {
        $workItem = $this->packagePresenter->item($packageItem);
        $quantityBasis = $workItem['quantity_basis'] ?? '';

        if (is_array($quantityBasis)) {
            $workItem['quantity_basis'] = (string) ($quantityBasis['description'] ?? $quantityBasis['formula'] ?? '');
            $workItem['quantity_formula'] = (string) ($quantityBasis['formula'] ?? '');
        } else {
            $workItem['quantity_basis'] = (string) $quantityBasis;
            $workItem['quantity_formula'] = (string) ($workItem['quantity_formula'] ?? '');
        }

        $resources = is_array($workItem['resources'] ?? null) ? $workItem['resources'] : [];
        $workItem['materials'] = is_array($resources['materials'] ?? null) ? array_values($resources['materials']) : [];
        $workItem['labor'] = is_array($resources['labor'] ?? null) ? array_values($resources['labor']) : [];
        $workItem['machinery'] = is_array($resources['machinery'] ?? null) ? array_values($resources['machinery']) : [];
        $workItem['other_resources'] = is_array($resources['other'] ?? null) ? array_values($resources['other']) : [];
        $workItem['description'] = (string) ($workItem['description'] ?? '');
        $workItem['work_category'] = (string) ($workItem['work_category'] ?? 'custom');
        $workItem['work_cost'] = (float) ($workItem['direct_cost'] ?? 0);
        $workItem['materials_cost'] = (float) ($workItem['materials_cost'] ?? 0);
        $workItem['machinery_cost'] = (float) ($workItem['machinery_cost'] ?? 0);
        $workItem['labor_cost'] = (float) ($workItem['labor_cost'] ?? 0);
        $workItem['validation_flags'] = $this->arrayValues($workItem['validation_flags'] ?? $workItem['flags'] ?? []);
        $workItem['source_refs'] = $this->sourceRefs($workItem);
        $workItem['confidence'] = (float) ($workItem['confidence'] ?? 0.7);

        return $workItem;
    }

    /**
     * @param  array<string, mixed>  $localEstimate
     * @param  array<string, mixed>  $section
     * @param  array<string, mixed>  $workItem
     * @return array<string, mixed>|null
     */
    private function reviewItem(array $localEstimate, array $section, array $workItem): ?array
    {
        $itemType = (string) ($workItem['item_type'] ?? 'priced_work');

        if (in_array($itemType, EstimateGenerationPackageItem::SERVICE_ITEM_TYPES, true)) {
            return null;
        }

        $flags = $this->flags($workItem);
        $genericReviewRequired = $this->noAirWorkItemPolicy->requiresReview($workItem);

        if ($genericReviewRequired) {
            $workItem = $this->noAirWorkItemPolicy->markRequiresReview($workItem);
            $flags = $this->flags($workItem);
        }

        $quantityReviewRequired = $itemType === EstimateGenerationPackageItem::QUANTITY_REVIEW_ITEM_TYPE
            || (string) ($workItem['pricing_blocker'] ?? '') === 'quantity_review_required'
            || in_array('quantity_review_required', $flags, true);
        $duplicateReviewRequired = in_array('requires_duplicate_review', $flags, true)
            || in_array('possible_duplicate_work_item', $flags, true);
        $normativeCodeRequired = ! $quantityReviewRequired && $this->normativeCodeRequired($workItem, $flags);
        $normativePriceRequired = ! $quantityReviewRequired && $this->normativePriceRequired($workItem, $flags);
        $normativeReviewRequired = ! $quantityReviewRequired && $this->normativeReviewRequired($workItem, $flags);
        $pricingNotCalculated = ! $quantityReviewRequired && $this->pricingNotCalculated($workItem, $flags);
        $priceReviewRequired = ! $quantityReviewRequired && $this->priceReviewRequired($workItem, $flags);
        $hasAlternative = ! $quantityReviewRequired && $this->hasNormativeAlternative($workItem);
        $hasNormativeResourceReference = ! $quantityReviewRequired && $this->hasNormativeResourceReference($workItem);

        if (
            ! $quantityReviewRequired
            && ! $duplicateReviewRequired
            && ! $genericReviewRequired
            && ! $normativeCodeRequired
            && ! $normativePriceRequired
            && ! $normativeReviewRequired
            && ! $pricingNotCalculated
            && ! $priceReviewRequired
            && ! $hasAlternative
        ) {
            return null;
        }

        $severity = self::SEVERITY_OPTIONAL;
        $requiredAction = self::ACTION_REVIEW_NORM;

        if ($quantityReviewRequired) {
            $severity = self::SEVERITY_BLOCKING;
            $requiredAction = self::ACTION_CONFIRM_QUANTITY;
        } elseif ($duplicateReviewRequired) {
            $severity = self::SEVERITY_BLOCKING;
            $requiredAction = self::ACTION_RESOLVE_DUPLICATE;
        } elseif ($genericReviewRequired) {
            $severity = self::SEVERITY_BLOCKING;
            $requiredAction = self::ACTION_RESOLVE_GENERIC_WORK;
        } elseif ($normativeCodeRequired) {
            $severity = self::SEVERITY_BLOCKING;
            $requiredAction = self::ACTION_SELECT_NORM;
        } elseif ($normativePriceRequired) {
            $severity = self::SEVERITY_BLOCKING;
            $requiredAction = self::ACTION_CHECK_PRICE;
        } elseif ($genericReviewRequired || $normativeReviewRequired || $pricingNotCalculated) {
            $severity = self::SEVERITY_BLOCKING;
            $requiredAction = self::ACTION_SELECT_NORM;
        } elseif ($priceReviewRequired) {
            $severity = self::SEVERITY_WARNING;
            $requiredAction = self::ACTION_CHECK_PRICE;
        }

        $localEstimateKey = (string) ($localEstimate['key'] ?? '');
        $sectionKey = (string) ($section['key'] ?? '');
        $workItemKey = (string) ($workItem['key'] ?? '');

        return [
            'key' => $this->reviewKey($localEstimateKey, $sectionKey, $workItemKey),
            'local_estimate_key' => $localEstimateKey,
            'local_estimate_title' => (string) ($localEstimate['title'] ?? $localEstimateKey),
            'section_key' => $sectionKey,
            'section_title' => (string) ($section['title'] ?? $sectionKey),
            'work_item_key' => $workItemKey,
            'work_item' => $workItem,
            'severity' => $severity,
            'required_action' => $requiredAction,
            'reason_codes' => $this->reasonCodes(
                $quantityReviewRequired,
                $duplicateReviewRequired,
                $pricingNotCalculated,
                $normativeCodeRequired,
                $normativePriceRequired,
                $normativeReviewRequired,
                $priceReviewRequired,
                $hasAlternative,
                $genericReviewRequired,
                $hasNormativeResourceReference,
            ),
            'candidates_count' => $this->candidatesCount($workItem),
            'has_current_norm' => $this->hasCurrentNorm($workItem),
            'source_refs' => $this->sourceRefs($workItem),
            'pricing_blocker' => $this->nullableString($workItem['pricing_blocker'] ?? null),
            'pricing_status' => $this->nullableString($workItem['pricing_status'] ?? null),
            'normative_status' => $this->nullableString($this->normativeStatus($workItem)),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, int>
     */
    private function summary(array $items): array
    {
        $summary = [
            'total' => count($items),
            self::SEVERITY_BLOCKING => 0,
            self::SEVERITY_WARNING => 0,
            self::SEVERITY_OPTIONAL => 0,
            self::ACTION_CONFIRM_QUANTITY => 0,
            self::ACTION_SELECT_NORM => 0,
            self::ACTION_REVIEW_NORM => 0,
            self::ACTION_RESOLVE_DUPLICATE => 0,
            self::ACTION_RESOLVE_GENERIC_WORK => 0,
            self::ACTION_CHECK_PRICE => 0,
        ];

        foreach ($items as $item) {
            $severity = (string) ($item['severity'] ?? '');
            $action = (string) ($item['required_action'] ?? '');

            if (array_key_exists($severity, $summary)) {
                $summary[$severity]++;
            }

            if (array_key_exists($action, $summary)) {
                $summary[$action]++;
            }
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $workItem
     * @return array<int, string>
     */
    private function flags(array $workItem): array
    {
        return array_values(array_unique(array_filter(array_map('strval', [
            ...$this->arrayValues($workItem['validation_flags'] ?? []),
            ...$this->arrayValues($workItem['flags'] ?? []),
            ...$this->arrayValues(data_get($workItem, 'normative_match.warnings', [])),
            ...$this->arrayValues(data_get($workItem, 'normative_match.decision.warnings', [])),
        ]))));
    }

    /**
     * @param  array<string, mixed>  $workItem
     * @param  array<int, string>  $flags
     */
    private function normativeReviewRequired(array $workItem, array $flags): bool
    {
        $status = (string) $this->normativeStatus($workItem);
        $decisionStatus = (string) data_get($workItem, 'normative_match.decision.status', '');

        return in_array($status, self::NORMATIVE_REVIEW_STATUSES, true)
            || array_intersect($flags, self::NORMATIVE_REVIEW_FLAGS) !== []
            || data_get($workItem, 'normative_match.decision.can_use_for_pricing') === false
            || ($decisionStatus !== '' && ! in_array($decisionStatus, ['accepted', 'review_priced'], true));
    }

    /**
     * @param  array<string, mixed>  $workItem
     * @param  array<int, string>  $flags
     */
    private function pricingNotCalculated(array $workItem, array $flags): bool
    {
        $pricingBlocker = (string) ($workItem['pricing_blocker'] ?? '');

        return (string) ($workItem['pricing_status'] ?? '') === 'not_calculated'
            || $pricingBlocker !== ''
            || array_intersect($flags, self::PRICING_FLAGS) !== []
            || in_array((string) $this->normativeStatus($workItem), self::NORMATIVE_REVIEW_STATUSES, true);
    }

    /**
     * @param  array<string, mixed>  $workItem
     * @param  array<int, string>  $flags
     */
    private function normativePriceRequired(array $workItem, array $flags): bool
    {
        if (! $this->hasCurrentNorm($workItem)) {
            return false;
        }

        $markers = [
            ...$flags,
            (string) ($workItem['pricing_blocker'] ?? ''),
        ];

        return array_intersect($markers, self::NORMATIVE_PRICE_FLAGS) !== [];
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
        $markers = [
            ...$flags,
            (string) ($workItem['pricing_blocker'] ?? ''),
        ];

        return array_intersect($markers, self::NORMATIVE_CODE_FLAGS) !== []
            || (bool) ($metadata['normative_code_required'] ?? false)
            || (bool) ($metadata['normative_code_expected'] ?? false)
            || (string) ($metadata['generation_source'] ?? '') === 'project_document_normative_reference'
            || $this->hasProjectDocumentNormReference($sourceRefs);
    }

    /**
     * @param  array<string, mixed>  $workItem
     * @param  array<int, string>  $flags
     */
    private function priceReviewRequired(array $workItem, array $flags): bool
    {
        return (string) ($workItem['pricing_status'] ?? '') === 'calculated_review_required'
            || in_array('price_review_required', $flags, true)
            || in_array('low_confidence', $flags, true)
            || (bool) data_get($workItem, 'normative_match.decision.can_use_for_pricing', false)
                && $this->arrayValues(data_get($workItem, 'normative_match.warnings', [])) !== [];
    }

    /**
     * @param  array<string, mixed>  $workItem
     */
    private function hasNormativeAlternative(array $workItem): bool
    {
        $currentNormId = $this->normId(data_get($workItem, 'normative_match'));
        $candidateIds = [];

        foreach ($this->arrayValues($workItem['normative_candidates'] ?? []) as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $candidateId = $this->normId($candidate);

            if ($candidateId !== null) {
                $candidateIds[] = $candidateId;
            }
        }

        if ($candidateIds === []) {
            return false;
        }

        if ($currentNormId === null) {
            return true;
        }

        return in_array($currentNormId, $candidateIds, true) === false
            || count(array_unique($candidateIds)) > 1;
    }

    /**
     * @param  array<string, mixed>  $workItem
     */
    private function hasCurrentNorm(array $workItem): bool
    {
        $match = data_get($workItem, 'normative_match');

        if (! is_array($match)) {
            return false;
        }

        if ($this->normId($match) !== null) {
            return true;
        }

        return trim((string) ($match['code'] ?? '')) !== '';
    }

    private function normId(mixed $payload): ?int
    {
        if (! is_array($payload)) {
            return null;
        }

        $value = $payload['norm_id'] ?? $payload['id'] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param  array<string, mixed>  $workItem
     */
    private function normativeStatus(array $workItem): ?string
    {
        $status = data_get($workItem, 'normative_match.decision.status')
            ?: data_get($workItem, 'normative_match.status')
            ?: ($workItem['normative_status'] ?? null);

        return $this->nullableString($status);
    }

    /**
     * @return array<int, string>
     */
    private function reasonCodes(
        bool $quantityReviewRequired,
        bool $duplicateReviewRequired,
        bool $pricingNotCalculated,
        bool $normativeCodeRequired,
        bool $normativePriceRequired,
        bool $normativeReviewRequired,
        bool $priceReviewRequired,
        bool $hasAlternative,
        bool $genericReviewRequired,
        bool $hasNormativeResourceReference,
    ): array {
        $reasons = [];

        if ($quantityReviewRequired) {
            $reasons[] = 'quantity_review_required';
        }

        if ($duplicateReviewRequired) {
            $reasons[] = 'requires_duplicate_review';
        }

        if ($pricingNotCalculated) {
            $reasons[] = 'pricing_not_calculated';
        }

        if ($normativeCodeRequired) {
            $reasons[] = 'normative_code_required';
        }

        if ($hasNormativeResourceReference) {
            $reasons[] = 'normative_resource_reference';
        }

        if ($normativePriceRequired) {
            $reasons[] = 'normative_price_required';
        }

        if ($genericReviewRequired) {
            $reasons[] = EstimateGenerationNoAirWorkItemPolicy::FLAG;
        }

        if ($normativeReviewRequired && ! $normativePriceRequired) {
            $reasons[] = 'normative_requires_review';
        }

        if ($priceReviewRequired) {
            $reasons[] = 'price_review_required';
        }

        if ($hasAlternative) {
            $reasons[] = 'normative_alternative_available';
        }

        return array_values(array_unique($reasons));
    }

    /**
     * @param  array<string, mixed>  $workItem
     */
    private function candidatesCount(array $workItem): int
    {
        return count(array_filter($this->arrayValues($workItem['normative_candidates'] ?? []), 'is_array'));
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
     * @param  array<string, mixed>  $workItem
     */
    private function hasNormativeResourceReference(array $workItem): bool
    {
        $metadata = is_array($workItem['metadata'] ?? null) ? $workItem['metadata'] : [];

        return trim((string) ($metadata['normative_resource_code'] ?? '')) !== ''
            || in_array((string) ($metadata['normative_reference_kind'] ?? ''), ['fsbc_resource', 'fsbc_machine_resource', 'ksr_resource'], true);
    }

    /**
     * @param  array<int, mixed>  $sourceRefs
     */
    private function hasProjectDocumentNormReference(array $sourceRefs): bool
    {
        foreach ($this->arrayValues($sourceRefs) as $sourceRef) {
            if (is_array($sourceRef) && (string) ($sourceRef['type'] ?? '') === 'project_document_norm_reference') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $workItem
     * @return array<int, array<string, mixed>>
     */
    private function sourceRefs(array $workItem): array
    {
        return array_values(array_filter(
            $this->arrayValues($workItem['source_refs'] ?? []),
            static fn (mixed $sourceRef): bool => is_array($sourceRef)
        ));
    }

    /**
     * @return array<int, mixed>
     */
    private function arrayValues(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function reviewKey(string $localEstimateKey, string $sectionKey, string $workItemKey): string
    {
        return implode(':', array_filter([$localEstimateKey, $sectionKey, $workItemKey], static fn (string $part): bool => $part !== ''));
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function dedupeKey(array $item): string
    {
        return (string) ($item['work_item_key'] ?? $item['key'] ?? '');
    }
}
