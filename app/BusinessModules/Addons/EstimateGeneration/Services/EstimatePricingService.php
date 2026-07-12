<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

use App\BusinessModules\Addons\EstimateGeneration\Pricing\MissingRegionalPrice;
use App\BusinessModules\Addons\EstimateGeneration\Pricing\PriceSnapshotData;
use App\BusinessModules\Addons\EstimateGeneration\Pricing\ResolveRegionalPrice;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

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

            try {
                [$workItem, $resourceSnapshots, $costs] = $this->resolveResources($workItem, $regionalContext);
                $materialsCost = $costs['materials'];
                $laborCost = $costs['labor'];
                $machineryCost = $costs['machinery'];
                $otherCost = $costs['other_resources'];
                $workCost = BigDecimal::zero()->toScale(2);
                $total = $materialsCost->plus($laborCost)->plus($machineryCost)->plus($otherCost)->plus($workCost)->toScale(2, RoundingMode::HalfUp);
                $workItem['work_cost'] = (string) $workCost;
                $workItem['materials_cost'] = (string) $materialsCost->toScale(2, RoundingMode::HalfUp);
                $workItem['machinery_cost'] = (string) $machineryCost->toScale(2, RoundingMode::HalfUp);
                $workItem['labor_cost'] = (string) $laborCost->toScale(2, RoundingMode::HalfUp);
                $workItem['total_cost'] = (string) $total;
                $workItem['price_snapshot'] = $this->snapshot($resourceSnapshots, $workCost, $total)->toArray();
            } catch (MissingRegionalPrice|\Brick\Math\Exception\MathException) {
                $this->blockMissingSnapshot($workItem);

                continue;
            }

            if ($total->isLessThanOrEqualTo(0)) {
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

    private function resolveResources(array $workItem, array $regionalContext): array
    {
        $resourceSnapshots = [];
        $costs = [];
        foreach (['materials', 'labor', 'machinery', 'other_resources'] as $group) {
            $costs[$group] = BigDecimal::zero();
            foreach ($workItem[$group] ?? [] as $index => $resource) {
                if (! is_array($resource)) {
                    throw MissingRegionalPrice::forResource(0);
                }
                $snapshot = $this->regionalPrice->handle($resource, $regionalContext)->toArray();
                $resourceSnapshots[] = $snapshot;
                $costs[$group] = $costs[$group]->plus($snapshot['final_amount']);
                $workItem[$group][$index]['unit_price'] = $snapshot['base_amount'];
                $workItem[$group][$index]['total_price'] = $snapshot['final_amount'];
            }
        }
        if ($resourceSnapshots === []) {
            throw MissingRegionalPrice::forResource(0);
        }

        return [$workItem, $resourceSnapshots, $costs];
    }

    private function snapshot(array $resourceSnapshots, BigDecimal $workCost, BigDecimal $total): PriceSnapshotData
    {

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

        $base = BigDecimal::zero();
        foreach ($resourceSnapshots as $resourceSnapshot) {
            $base = $base->plus($resourceSnapshot['final_amount']);
        }
        $manifest = self::canonicalEvidenceManifest($resourceSnapshots);

        return new PriceSnapshotData(
            regionId: $first['region_id'],
            zoneId: $first['zone_id'],
            periodId: $first['period_id'],
            versionId: $first['version_id'],
            sourceType: 'regional_resource_aggregate',
            sourceReference: 'sha256:'.hash('sha256', $manifest),
            baseAmount: (string) $base->toScale(2, RoundingMode::HalfUp),
            coefficients: [
                'work_cost' => (string) $workCost->toScale(2, RoundingMode::HalfUp),
                'resource_evidence' => $resourceSnapshots,
            ],
            finalAmount: (string) $total->toScale(2, RoundingMode::HalfUp),
            currency: $first['currency'],
            capturedAt: now()->toIso8601String(),
        );
    }

    private static function canonicalEvidenceManifest(array $resourceSnapshots): string
    {
        $references = array_column($resourceSnapshots, 'source_reference');
        sort($references, SORT_STRING);

        return implode('|', $references);
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
        $workItem['pricing_blocker'] ??= 'missing_price_snapshot';
        $workItem['validation_flags'] = array_values(array_unique([
            ...(is_array($workItem['validation_flags'] ?? null) ? $workItem['validation_flags'] : []),
            'missing_price_snapshot',
        ]));
    }
}
