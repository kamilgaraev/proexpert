<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Services;

use App\Models\EstimateItem;

class PriceDeviationAnalyzer
{
    private const WARNING_THRESHOLD = 15.0;
    private const BLOCK_THRESHOLD = 25.0;

    public function analyze(array $splits): array
    {
        $totalDeviation = 0.0;
        $maxDeviationPercent = 0.0;
        $items = [];

        foreach ($splits as $split) {
            $itemId = $split['estimate_item_id'] ?? null;
            if (!$itemId) {
                continue;
            }

            $estimateItem = EstimateItem::find($itemId);
            if (!$estimateItem) {
                continue;
            }

            $planPrice = (float) $estimateItem->unit_price;
            $actualPrice = (float) ($split['unit_price_actual'] ?? 0);
            $quantity = (float) ($split['quantity'] ?? 0);

            $deviationAmount = ($actualPrice - $planPrice) * $quantity;
            $deviationPercent = $planPrice > 0 ? (($actualPrice / $planPrice) - 1) * 100 : 0;

            $totalDeviation += $deviationAmount;
            $maxDeviationPercent = max($maxDeviationPercent, abs($deviationPercent));

            $items[] = [
                'estimate_item_id' => $itemId,
                'name' => $estimateItem->name,
                'unit_price_plan' => $planPrice,
                'unit_price_actual' => $actualPrice,
                'quantity' => $quantity,
                'deviation_amount' => round($deviationAmount, 2),
                'deviation_percent' => round($deviationPercent, 2),
            ];
        }

        return [
            'total_deviation' => round($totalDeviation, 2),
            'max_single_deviation_percent' => round($maxDeviationPercent, 2),
            'requires_approval' => $maxDeviationPercent > self::WARNING_THRESHOLD,
            'is_blocked' => $maxDeviationPercent > self::BLOCK_THRESHOLD,
            'items' => $items,
        ];
    }
}
