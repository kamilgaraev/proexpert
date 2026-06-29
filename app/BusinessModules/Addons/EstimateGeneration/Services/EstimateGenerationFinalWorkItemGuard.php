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
            || (string) ($workItem['pricing_blocker'] ?? '') === 'quantity_review_required'
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
}
