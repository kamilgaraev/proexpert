<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

use App\BusinessModules\Addons\EstimateGeneration\Pricing\MissingRegionalPrice;
use App\BusinessModules\Addons\EstimateGeneration\Pricing\PriceSnapshotData;
use App\BusinessModules\Addons\EstimateGeneration\Pricing\ResolveRegionalPrice;

class EstimatePricingService
{
    private ResolveRegionalPrice $regionalPrice;

    public function __construct(?ResolveRegionalPrice $regionalPrice = null)
    {
        $this->regionalPrice = $regionalPrice ?? new ResolveRegionalPrice;
    }

    public function price(array $workItems, array $regionalContext = []): array
    {
        foreach ($workItems as &$workItem) {
            if ((string) ($workItem['item_type'] ?? 'priced_work') === 'quantity_review') {
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
                $workItem['price_snapshot'] = null;
                $workItem['pricing_status'] = 'not_calculated';
                $workItem['pricing_blocker'] = 'quantity_review_required';

                continue;
            }

            if (in_array((string) ($workItem['item_type'] ?? 'priced_work'), ['operation', 'resource_note', 'review_note'], true)) {
                $workItem['work_cost'] = 0;
                $workItem['materials_cost'] = 0;
                $workItem['machinery_cost'] = 0;
                $workItem['labor_cost'] = 0;
                $workItem['total_cost'] = 0;
                $workItem['price_snapshot'] = null;

                continue;
            }

            $materialsCost = array_sum(array_column($workItem['materials'], 'total_price'));
            $laborCost = array_sum(array_column($workItem['labor'], 'total_price'));
            $machineryCost = array_sum(array_column($workItem['machinery'], 'total_price'));
            $workCost = round($laborCost + ($materialsCost * 0.18), 2);
            $total = round($materialsCost + $laborCost + $machineryCost + $workCost, 2);

            $workItem['work_cost'] = $workCost;
            $workItem['materials_cost'] = round($materialsCost, 2);
            $workItem['machinery_cost'] = round($machineryCost, 2);
            $workItem['labor_cost'] = round($laborCost, 2);
            $workItem['total_cost'] = $total;

            if ($regionalContext !== [] && $total > 0) {
                try {
                    $workItem['price_snapshot'] = $this->snapshot($workItem, $regionalContext, $workCost, $total)->toArray();
                } catch (MissingRegionalPrice) {
                    $this->blockMissingSnapshot($workItem);

                    continue;
                }
            }

            if ($total <= 0) {
                $workItem['pricing_status'] = 'not_calculated';
                $workItem['pricing_blocker'] = $workItem['pricing_blocker'] ?? 'pricing_not_calculated';
                $workItem['validation_flags'] = array_values(array_unique([
                    ...(is_array($workItem['validation_flags'] ?? null) ? $workItem['validation_flags'] : []),
                    'pricing_not_calculated',
                ]));
            }
        }

        return $workItems;
    }

    private function snapshot(array $workItem, array $regionalContext, float $workCost, float $total): PriceSnapshotData
    {
        $resourceSnapshots = [];
        foreach (['materials', 'labor', 'machinery', 'other_resources'] as $group) {
            foreach ($workItem[$group] ?? [] as $resource) {
                if (! is_array($resource) || (float) ($resource['total_price'] ?? 0) <= 0) {
                    continue;
                }
                $resourceSnapshots[] = $this->regionalPrice->handle($resource, $regionalContext)->toArray();
            }
        }
        if ($resourceSnapshots === []) {
            throw MissingRegionalPrice::forResource(0);
        }

        $first = $resourceSnapshots[0];
        foreach ($resourceSnapshots as $snapshot) {
            if ($snapshot['region_id'] !== $first['region_id']
                || $snapshot['zone_id'] !== $first['zone_id']
                || $snapshot['period_id'] !== $first['period_id']
                || $snapshot['version_id'] !== $first['version_id']
                || $snapshot['currency'] !== $first['currency']) {
                throw MissingRegionalPrice::forResource(0);
            }
        }

        $base = round(array_sum(array_column($resourceSnapshots, 'final_amount')), 2);
        $references = array_column($resourceSnapshots, 'source_reference');
        sort($references, SORT_STRING);

        return new PriceSnapshotData(
            regionId: $first['region_id'],
            zoneId: $first['zone_id'],
            periodId: $first['period_id'],
            versionId: $first['version_id'],
            sourceType: 'regional_resource_aggregate',
            sourceReference: 'sha256:'.hash('sha256', implode('|', $references)),
            baseAmount: number_format($base, 2, '.', ''),
            coefficients: [
                'work_cost' => number_format($workCost, 2, '.', ''),
                'resource_evidence' => $resourceSnapshots,
            ],
            finalAmount: number_format($total, 2, '.', ''),
            currency: $first['currency'],
            capturedAt: now()->toIso8601String(),
        );
    }

    private function blockMissingSnapshot(array &$workItem): void
    {
        $workItem['work_cost'] = 0;
        $workItem['materials_cost'] = 0;
        $workItem['machinery_cost'] = 0;
        $workItem['labor_cost'] = 0;
        $workItem['total_cost'] = 0;
        $workItem['price_source'] = null;
        $workItem['price_snapshot'] = null;
        $workItem['pricing_status'] = 'not_calculated';
        $workItem['pricing_blocker'] = 'missing_price_snapshot';
        $workItem['validation_flags'] = array_values(array_unique([
            ...(is_array($workItem['validation_flags'] ?? null) ? $workItem['validation_flags'] : []),
            'missing_price_snapshot',
        ]));
    }
}
