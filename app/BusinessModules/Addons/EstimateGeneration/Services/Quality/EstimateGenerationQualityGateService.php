<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Quality;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\EstimateGenerationQualityReportData;

class EstimateGenerationQualityGateService
{
    private const HOUSE_MIN_ITEMS = 250;
    private const WAREHOUSE_MIN_ITEMS = 600;
    private const HOUSE_TOTAL_PER_M2_MIN = 25000;
    private const HOUSE_TOTAL_PER_M2_MAX = 300000;
    private const WAREHOUSE_TOTAL_PER_M2_MIN = 15000;
    private const WAREHOUSE_TOTAL_PER_M2_MAX = 180000;
    private const SECTION_SHARE_MAX = 0.45;
    private const LINE_SHARE_MAX = 0.35;
    private const LINE_TOTAL_PER_M2_MAX = 800000;

    /**
     * @param array<string, mixed> $draft
     */
    public function evaluate(array $draft): EstimateGenerationQualityReportData
    {
        $profile = is_array($draft['object_profile'] ?? null) ? $draft['object_profile'] : [];
        $totals = is_array($draft['totals'] ?? null) ? $draft['totals'] : [];
        $objectType = mb_strtolower((string) ($profile['object_type'] ?? $profile['building_type'] ?? 'custom'));
        $area = (float) ($profile['area'] ?? 0);
        $totalCost = (float) ($totals['total_cost'] ?? $totals['base_total_cost'] ?? 0);
        $itemsCount = (int) ($totals['work_items_count'] ?? $this->countItems($draft));
        $pricedWorkItemsRaw = data_get($draft, 'quality_summary.priced_work_items');
        $operationWorkItemsRaw = data_get($draft, 'quality_summary.operation_work_items');
        $notCalculatedWorkItemsRaw = data_get($draft, 'quality_summary.not_calculated_work_items');
        $safeNormRequiredWorkItemsRaw = data_get($draft, 'quality_summary.safe_norm_required_work_items');
        $hasPricingCoverageSummary = $pricedWorkItemsRaw !== null
            || $notCalculatedWorkItemsRaw !== null
            || $safeNormRequiredWorkItemsRaw !== null;
        $pricedWorkItems = (int) ($pricedWorkItemsRaw ?? 0);
        $operationWorkItems = (int) ($operationWorkItemsRaw ?? 0);
        $pricedDenominator = max($itemsCount - $operationWorkItems, 0);
        $notCalculatedWorkItems = (int) ($notCalculatedWorkItemsRaw ?? 0);
        $safeNormRequiredWorkItems = (int) ($safeNormRequiredWorkItemsRaw ?? 0);
        $pricingCoverageIncomplete = $hasPricingCoverageSummary
            && $pricedDenominator > 0
            && ($pricedWorkItems < $pricedDenominator || $notCalculatedWorkItems > 0 || $safeNormRequiredWorkItems > 0);
        $lineAnomalies = $this->lineAnomalies($draft, $totalCost, $area);
        $criticalFlags = [];
        $warningFlags = [];

        $minItems = $this->isWarehouse($objectType) ? self::WAREHOUSE_MIN_ITEMS : self::HOUSE_MIN_ITEMS;
        if ($itemsCount > 0 && $itemsCount < $minItems) {
            $criticalFlags[] = 'insufficient_detail';
        }

        if ($area > 0 && $totalCost > 0 && !$pricingCoverageIncomplete) {
            $totalPerSquareMeter = $totalCost / $area;
            [$min, $max] = $this->isWarehouse($objectType)
                ? [self::WAREHOUSE_TOTAL_PER_M2_MIN, self::WAREHOUSE_TOTAL_PER_M2_MAX]
                : [self::HOUSE_TOTAL_PER_M2_MIN, self::HOUSE_TOTAL_PER_M2_MAX];

            if ($totalPerSquareMeter < $min || $totalPerSquareMeter > $max) {
                $criticalFlags[] = 'total_out_of_range';
            }
        }

        if ($totalCost > 0 && !$pricingCoverageIncomplete && $this->hasAnomalousSectionShare($draft, $totalCost)) {
            $criticalFlags[] = 'section_total_anomaly';
        }

        if (!$pricingCoverageIncomplete && $lineAnomalies !== []) {
            $criticalFlags[] = 'line_total_anomaly';
        }

        if (($totals['zero_price_work_items'] ?? 0) > 0 && !$pricingCoverageIncomplete) {
            $criticalFlags[] = 'missing_prices';
        }

        $existingFlags = $this->collectExistingFlags($draft);
        foreach (['missing_price', 'missing_resources'] as $flag) {
            if (in_array($flag, $existingFlags, true)) {
                if ($pricingCoverageIncomplete) {
                    $warningFlags[] = $flag;
                } else {
                    $criticalFlags[] = $flag;
                }
            }
        }

        if (in_array('regional_context_missing', $existingFlags, true)) {
            $criticalFlags[] = 'regional_context_missing';
        }

        if ($pricingCoverageIncomplete) {
            $warningFlags[] = 'pricing_coverage_incomplete';
        }

        foreach (['low_confidence', 'normative_match_low_confidence', 'market_price_used'] as $flag) {
            if (in_array($flag, $existingFlags, true)) {
                $warningFlags[] = $flag;
            }
        }

        $criticalFlags = array_values(array_unique($criticalFlags));
        $warningFlags = array_values(array_unique($warningFlags));
        $level = $criticalFlags === [] ? 'passed' : 'review_required';

        if (
            in_array('total_out_of_range', $criticalFlags, true)
            || in_array('section_total_anomaly', $criticalFlags, true)
            || in_array('line_total_anomaly', $criticalFlags, true)
        ) {
            $level = 'blocked';
        }

        return new EstimateGenerationQualityReportData(
            level: $level,
            criticalFlags: $criticalFlags,
            warningFlags: $warningFlags,
            metrics: [
                'items_count' => $itemsCount,
                'total_cost' => round($totalCost, 2),
                'area' => $area > 0 ? $area : null,
                'total_per_square_meter' => $area > 0 ? round($totalCost / $area, 2) : null,
                'target_items_min' => $minItems,
                'max_line_total' => $lineAnomalies !== [] ? max(array_column($lineAnomalies, 'total_cost')) : 0,
                'max_line_share' => $lineAnomalies !== [] ? max(array_column($lineAnomalies, 'share')) : 0,
                'anomalous_line_keys' => array_values(array_column($lineAnomalies, 'key')),
                'pricing_coverage' => $pricedDenominator > 0 ? round($pricedWorkItems / $pricedDenominator, 4) : 0.0,
            ],
        );
    }

    private function isWarehouse(string $objectType): bool
    {
        return str_contains($objectType, 'warehouse')
            || str_contains($objectType, 'склад')
            || str_contains($objectType, 'industrial');
    }

    /**
     * @param array<string, mixed> $draft
     * @return array<int, array{key: string, total_cost: float, share: float}>
     */
    private function lineAnomalies(array $draft, float $totalCost, float $area): array
    {
        if ($totalCost <= 0) {
            return [];
        }

        $anomalies = [];

        foreach ($draft['local_estimates'] ?? [] as $localEstimate) {
            foreach ($localEstimate['sections'] ?? [] as $section) {
                foreach ($section['work_items'] ?? [] as $workItem) {
                    $lineTotal = (float) ($workItem['total_cost'] ?? 0);

                    if ($lineTotal <= 0) {
                        continue;
                    }

                    $share = $lineTotal / $totalCost;
                    $totalPerProjectSquareMeter = $area > 0 ? $lineTotal / $area : 0;
                    $normativeAccepted = $this->isNormativeAccepted($workItem);
                    $pricedMismatch = $this->hasPricedNormativeMismatch($workItem);

                    if (
                        $pricedMismatch
                        || $totalPerProjectSquareMeter > self::LINE_TOTAL_PER_M2_MAX
                        || ($share > self::LINE_SHARE_MAX && !$normativeAccepted)
                    ) {
                        $anomalies[] = [
                            'key' => (string) ($workItem['key'] ?? $workItem['name'] ?? 'work_item'),
                            'total_cost' => round($lineTotal, 2),
                            'share' => round($share, 4),
                        ];
                    }
                }
            }
        }

        return $anomalies;
    }

    /**
     * @param array<string, mixed> $workItem
     */
    private function isNormativeAccepted(array $workItem): bool
    {
        $match = is_array($workItem['normative_match'] ?? null) ? $workItem['normative_match'] : [];
        $decision = is_array($match['decision'] ?? null) ? $match['decision'] : [];

        if (($decision['status'] ?? null) === 'accepted') {
            return true;
        }

        return ($match['status'] ?? null) === 'matched' && ($decision['status'] ?? null) === null;
    }

    /**
     * @param array<string, mixed> $workItem
     */
    private function hasPricedNormativeMismatch(array $workItem): bool
    {
        $lineTotal = (float) ($workItem['total_cost'] ?? 0);
        if ($lineTotal <= 0) {
            return false;
        }

        $match = is_array($workItem['normative_match'] ?? null) ? $workItem['normative_match'] : [];
        $decision = is_array($match['decision'] ?? null) ? $match['decision'] : [];
        $warnings = array_values(array_unique([
            ...array_map('strval', $match['warnings'] ?? []),
            ...array_map('strval', $decision['warnings'] ?? []),
        ]));

        return in_array('unit_mismatch', $warnings, true) || in_array('scope_mismatch', $warnings, true);
    }

    /**
     * @param array<string, mixed> $draft
     */
    private function countItems(array $draft): int
    {
        $count = 0;

        foreach ($draft['local_estimates'] ?? [] as $localEstimate) {
            foreach ($localEstimate['sections'] ?? [] as $section) {
                $count += count($section['work_items'] ?? []);
            }

            if (($localEstimate['sections'] ?? []) === [] && isset($localEstimate['totals']['items_count'])) {
                $count += (int) $localEstimate['totals']['items_count'];
            }
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $draft
     */
    private function hasAnomalousSectionShare(array $draft, float $totalCost): bool
    {
        foreach ($draft['local_estimates'] ?? [] as $localEstimate) {
            $localTotal = (float) ($localEstimate['totals']['total_cost'] ?? 0);

            if ($localTotal <= 0) {
                continue;
            }

            if (($localTotal / $totalCost) > self::SECTION_SHARE_MAX) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $draft
     * @return array<int, string>
     */
    private function collectExistingFlags(array $draft): array
    {
        $flags = [];

        foreach ($draft['problem_flags'] ?? [] as $flag) {
            $flags[] = (string) $flag;
        }

        foreach ($draft['local_estimates'] ?? [] as $localEstimate) {
            foreach ($localEstimate['validation_flags'] ?? [] as $flag) {
                $flags[] = (string) $flag;
            }

            foreach ($localEstimate['sections'] ?? [] as $section) {
                foreach ($section['validation_flags'] ?? [] as $flag) {
                    $flags[] = (string) $flag;
                }

                foreach ($section['work_items'] ?? [] as $workItem) {
                    foreach ($workItem['validation_flags'] ?? [] as $flag) {
                        $flags[] = (string) $flag;
                    }
                }
            }
        }

        return array_values(array_unique($flags));
    }
}
