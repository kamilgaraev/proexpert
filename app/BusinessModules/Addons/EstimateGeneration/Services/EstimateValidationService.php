<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

class EstimateValidationService
{
    /**
     * @param array<string, mixed> $draft
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
        $zeroPriceWorkItemsCount = 0;
        $normativeMatchedWorkItemsCount = 0;
        $marketEstimateWorkItemsCount = 0;

        foreach ($draft['local_estimates'] as $localIndex => $localEstimate) {
            $localFlags = [];
            if ($localEstimate['source_refs'] === []) {
                $localFlags[] = 'weak_source_reference';
            }

            foreach ($localEstimate['sections'] as $sectionIndex => $section) {
                $sectionFlags = [];
                if (mb_strtolower($section['title']) === 'прочее') {
                    $sectionFlags[] = 'generic_section_name';
                }

                $sectionTotal = 0.0;
                foreach ($section['work_items'] as $workIndex => $workItem) {
                    $workItemsCount++;
                    $flags = $workItem['validation_flags'] ?? [];
                    $total = (float) ($workItem['total_cost'] ?? 0);
                    $hasResources = ($workItem['materials'] ?? []) !== []
                        || ($workItem['labor'] ?? []) !== []
                        || ($workItem['machinery'] ?? []) !== [];

                    if (($workItem['quantity_basis'] ?? null) === null || $workItem['quantity_basis'] === '') {
                        $flags[] = 'missing_quantity_basis';
                    }

                    if ($total <= 0) {
                        $flags[] = 'missing_price';
                        $zeroPriceWorkItemsCount++;
                    } else {
                        $pricedWorkItemsCount++;
                    }

                    if (!$hasResources) {
                        $flags[] = 'missing_resources';
                    }

                    if ((float) ($workItem['quantity'] ?? 0) <= 0) {
                        $flags[] = 'suspicious_quantity';
                    }

                    if ((float) ($workItem['confidence'] ?? 0) < 0.6) {
                        $flags[] = 'low_confidence';
                    }

                    if (($workItem['normative_match']['status'] ?? null) === 'matched') {
                        $normativeMatchedWorkItemsCount++;
                    }

                    if (in_array('market_price_used', $flags, true) || ($workItem['price_source'] ?? null) === 'market_estimate') {
                        $marketEstimateWorkItemsCount++;
                    }

                    $flags = array_values(array_unique($flags));
                    $draft['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'][$workIndex]['validation_flags'] = $flags;
                    $sectionTotal += $total;
                    $confidenceSum += (float) ($workItem['confidence'] ?? 0);
                    $confidenceCount++;
                    $sectionFlags = array_values(array_unique([...$sectionFlags, ...$flags]));
                }

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
            $zeroPriceWorkItemsCount,
            $normativeMatchedWorkItemsCount,
            $marketEstimateWorkItemsCount,
            $projectFlags
        );

        return $draft;
    }

    /**
     * @param array<string, mixed> $draft
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
     * @param array<int, string> $projectFlags
     * @return array<string, mixed>
     */
    private function qualitySummary(
        int $totalWorkItems,
        int $pricedWorkItems,
        int $zeroPriceWorkItems,
        int $normativeMatchedWorkItems,
        int $marketEstimateWorkItems,
        array $projectFlags
    ): array {
        $criticalFlags = array_values(array_intersect($projectFlags, ['missing_price', 'missing_resources', 'regional_context_missing']));
        $warningFlags = array_values(array_diff($projectFlags, $criticalFlags));
        $status = 'ready';

        if ($totalWorkItems === 0 || $zeroPriceWorkItems === $totalWorkItems || $pricedWorkItems === 0) {
            $status = 'critical';
        } elseif ($zeroPriceWorkItems > 0 || $marketEstimateWorkItems > 0 || $criticalFlags !== []) {
            $status = 'review_required';
        }

        return [
            'status' => $status,
            'total_work_items' => $totalWorkItems,
            'priced_work_items' => $pricedWorkItems,
            'zero_price_work_items' => $zeroPriceWorkItems,
            'normative_matched_work_items' => $normativeMatchedWorkItems,
            'market_estimate_work_items' => $marketEstimateWorkItems,
            'critical_flags' => $criticalFlags,
            'warning_flags' => $warningFlags,
        ];
    }
}
