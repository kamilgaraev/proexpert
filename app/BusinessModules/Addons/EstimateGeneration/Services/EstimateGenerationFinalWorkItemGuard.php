<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackageItem;

final class EstimateGenerationFinalWorkItemGuard
{
    public function __construct(
        private readonly EstimateGenerationNoAirWorkItemPolicy $noAirWorkItemPolicy = new EstimateGenerationNoAirWorkItemPolicy(),
    ) {}

    /**
     * @param array<string, mixed> $workItem
     */
    public function isFinalEstimateWorkItem(array $workItem): bool
    {
        $type = (string) ($workItem['item_type'] ?? 'priced_work');

        if ($type === EstimateGenerationPackageItem::QUANTITY_REVIEW_ITEM_TYPE) {
            return false;
        }

        if (in_array($type, EstimateGenerationPackageItem::SERVICE_ITEM_TYPES, true)) {
            return false;
        }

        if ($this->hasUnconfirmedQuantityReviewTrace($workItem)) {
            return false;
        }

        if ($this->noAirWorkItemPolicy->requiresReview($workItem)) {
            return false;
        }

        if ((string) ($workItem['pricing_status'] ?? '') !== 'calculated') {
            return false;
        }

        if ($this->normativeRateCode($workItem) === null) {
            return false;
        }

        if (!$this->hasAcceptedNormativeMatch($workItem)) {
            return false;
        }

        if (!$this->hasPricedNormativeResources($workItem)) {
            return false;
        }

        return (float) ($workItem['quantity'] ?? 0) > 0 && (float) ($workItem['total_cost'] ?? 0) > 0;
    }

    /**
     * @param array<string, mixed> $workItem
     */
    private function hasUnconfirmedQuantityReviewTrace(array $workItem): bool
    {
        $flags = [
            ...array_map('strval', is_array($workItem['validation_flags'] ?? null) ? $workItem['validation_flags'] : []),
            ...array_map('strval', is_array($workItem['flags'] ?? null) ? $workItem['flags'] : []),
        ];

        if (
            in_array('quantity_review_required', $flags, true)
            || in_array('document_takeoff_required', $flags, true)
            || (string) ($workItem['pricing_blocker'] ?? '') === 'quantity_review_required'
            || (string) ($workItem['pricing_blocker'] ?? '') === 'document_takeoff_required'
        ) {
            return true;
        }

        $metadata = is_array($workItem['metadata'] ?? null) ? $workItem['metadata'] : [];
        if ((string) ($metadata['display_role'] ?? '') === EstimateGenerationPackageItem::QUANTITY_REVIEW_ITEM_TYPE) {
            return true;
        }

        if (array_key_exists('quantity_feedback', $metadata)) {
            $feedback = is_array($metadata['quantity_feedback']) ? $metadata['quantity_feedback'] : [];

            return (string) ($feedback['status'] ?? '') !== 'confirmed_by_user';
        }

        return false;
    }

    /**
     * @param array<string, mixed> $workItem
     */
    public function normativeRateCode(array $workItem): ?string
    {
        $code = trim((string) ($workItem['normative_rate_code'] ?? data_get($workItem, 'normative_match.code', '')));

        return $code !== '' ? $code : null;
    }

    /**
     * @param array<string, mixed> $workItem
     */
    private function hasAcceptedNormativeMatch(array $workItem): bool
    {
        $match = is_array($workItem['normative_match'] ?? null) ? $workItem['normative_match'] : [];

        if ((string) ($match['status'] ?? '') !== 'matched') {
            return false;
        }

        if ((int) ($match['resources_count'] ?? 0) <= 0 || (int) ($match['priced_resources_count'] ?? 0) <= 0) {
            return false;
        }

        $decision = is_array($match['decision'] ?? null) ? $match['decision'] : [];

        return (string) ($decision['status'] ?? '') === 'accepted';
    }

    /**
     * @param array<string, mixed> $workItem
     */
    private function hasPricedNormativeResources(array $workItem): bool
    {
        foreach (['materials', 'labor', 'machinery', 'other_resources'] as $resourceKey) {
            $resources = is_array($workItem[$resourceKey] ?? null) ? $workItem[$resourceKey] : [];

            foreach ($resources as $resource) {
                if (is_array($resource) && (float) ($resource['total_price'] ?? 0) > 0) {
                    return true;
                }
            }
        }

        return false;
    }
}
