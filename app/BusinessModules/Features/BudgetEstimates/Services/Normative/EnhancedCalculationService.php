<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Normative;

use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\NormativeRate;
use App\Models\PriceIndex;
use App\Models\RegionalCoefficient;
use App\Repositories\PriceIndexRepository;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class EnhancedCalculationService
{
    public function __construct(
        protected PriceIndexRepository $priceIndexRepository
    ) {}

    public function calculateItemFromNormativeRate(
        EstimateItem $item,
        NormativeRate $rate,
        float $quantity,
        array $options = []
    ): EstimateItem {
        $item->normative_rate_id = $rate->id;
        $item->normative_rate_code = $rate->code;
        $item->name = $rate->name;
        $item->measurement_unit_id = $item->measurement_unit_id;
        $item->quantity = $quantity;

        $item->base_materials_cost = $rate->materials_cost * $quantity;
        $item->base_machinery_cost = $rate->machinery_cost * $quantity;
        $item->base_labor_cost = $rate->labor_cost * $quantity;
        
        $item->labor_hours = $rate->labor_hours * $quantity;
        $item->machinery_hours = $rate->machinery_hours * $quantity;

        if (!empty($options['apply_indices'])) {
            $this->applyPriceIndices($item, $options['calculation_date'] ?? now());
        } else {
            $item->materials_cost = $item->base_materials_cost;
            $item->machinery_cost = $item->base_machinery_cost;
            $item->labor_cost = $item->base_labor_cost;
        }

        if (!empty($options['coefficients'])) {
            $this->applyCoefficients($item, $options['coefficients']);
        } else {
            $item->coefficient_total = 1.0;
        }

        $item->direct_costs = $item->materials_cost + $item->machinery_cost + $item->labor_cost;

        $this->calculateResourceDetails($item, $rate);

        return $item;
    }

    public function applyPriceIndices(EstimateItem $item, Carbon $date): void
    {
        $item->materials_index = $this->getIndexValue('construction_general', $date) ?? 1.0;
        $item->machinery_index = $this->getIndexValue('construction_general', $date) ?? 1.0;
        $item->labor_index = $this->getIndexValue('construction_general', $date) ?? 1.0;

        $item->materials_cost = $item->base_materials_cost * $item->materials_index;
        $item->machinery_cost = $item->base_machinery_cost * $item->machinery_index;
        $item->labor_cost = $item->base_labor_cost * $item->labor_index;
    }

    public function applyCoefficients(EstimateItem $item, array $coefficients): void
    {
        $totalCoefficient = 1.0;
        $appliedCoefficients = [];

        foreach ($coefficients as $coefData) {
            if ($coefData instanceof RegionalCoefficient) {
                $coefficient = $coefData;
            } else {
                $coefficient = RegionalCoefficient::find($coefData['id'] ?? $coefData);
            }

            if ($coefficient) {
                $totalCoefficient *= $coefficient->coefficient_value;
                
                $appliedCoefficients[] = [
                    'id' => $coefficient->id,
                    'type' => $coefficient->coefficient_type,
                    'name' => $coefficient->name,
                    'value' => (float) $coefficient->coefficient_value,
                    'applies_to' => $coefData['applies_to'] ?? 'all',
                ];
            }
        }

        $item->applied_coefficients = $appliedCoefficients;
        $item->coefficient_total = $totalCoefficient;

        $item->materials_cost *= $totalCoefficient;
        $item->machinery_cost *= $totalCoefficient;
        $item->labor_cost *= $totalCoefficient;
        $item->direct_costs *= $totalCoefficient;
    }

    public function calculateEstimateTotal(Estimate $estimate): Estimate
    {
        $totals = $estimate->items()
            ->selectRaw('
                SUM(materials_cost) as total_materials,
                SUM(machinery_cost) as total_machinery,
                SUM(labor_cost) as total_labor,
                SUM(direct_costs) as total_direct,
                SUM(labor_hours) as total_labor_hours,
                SUM(machinery_hours) as total_machinery_hours
            ')
            ->first();

        $directCosts = (float) ($totals->total_direct ?? 0);

        $overheadAmount = $directCosts * ($estimate->overhead_rate / 100);
        $profitAmount = $directCosts * ($estimate->profit_rate / 100);

        $totalAmount = $directCosts + $overheadAmount + $profitAmount;
        $totalWithVat = $totalAmount * (1 + $estimate->vat_rate / 100);

        $estimate->total_direct_costs = $directCosts;
        $estimate->total_overhead_costs = $overheadAmount;
        $estimate->total_estimated_profit = $profitAmount;
        $estimate->total_amount = $totalAmount;
        $estimate->total_amount_with_vat = $totalWithVat;

        return $estimate;
    }

    public function recalculateEstimate(Estimate $estimate, array $options = []): Estimate
    {
        DB::transaction(function () use ($estimate, $options) {
            $items = $estimate->items()->with('normativeRate')->get();

            foreach ($items as $item) {
                if ($item->normativeRate) {
                    $this->recalculateItem($item, $options);
                    $item->save();
                }
            }

            $this->calculateEstimateTotal($estimate);
            $estimate->save();
        });

        return $estimate->fresh();
    }

    public function recalculateItem(EstimateItem $item, array $options = []): EstimateItem
    {
        if (!$item->normativeRate) {
            return $item;
        }

        $rate = $item->normativeRate;

        $item->base_materials_cost = $rate->materials_cost * $item->quantity;
        $item->base_machinery_cost = $rate->machinery_cost * $item->quantity;
        $item->base_labor_cost = $rate->labor_cost * $item->quantity;

        if (!empty($options['apply_indices'])) {
            $this->applyPriceIndices($item, $options['calculation_date'] ?? now());
        } else {
            $item->materials_cost = $item->base_materials_cost;
            $item->machinery_cost = $item->base_machinery_cost;
            $item->labor_cost = $item->base_labor_cost;
        }

        if ($item->hasAppliedCoefficients()) {
            $coefficients = [];
            foreach ($item->applied_coefficients as $coefData) {
                $coefficients[] = ['id' => $coefData['id']];
            }
            $this->applyCoefficients($item, $coefficients);
        }

        $item->direct_costs = $item->materials_cost + $item->machinery_cost + $item->labor_cost;

        return $item;
    }

    protected function calculateResourceDetails(EstimateItem $item, NormativeRate $rate): void
    {
        $resources = $rate->resources;
        
        if ($resources->isEmpty()) {
            return;
        }

        $resourceCalculation = [];

        foreach ($resources as $resource) {
            $resourceCalculation[] = [
                'type' => $resource->resource_type,
                'code' => $resource->code,
                'name' => $resource->name,
                'unit' => $resource->measurement_unit,
                'consumption_per_unit' => (float) $resource->consumption,
                'total_consumption' => (float) ($resource->consumption * $item->quantity),
                'unit_price' => (float) $resource->unit_price,
                'total_cost' => (float) ($resource->consumption * $item->quantity * $resource->unit_price),
            ];
        }

        $item->resource_calculation = $resourceCalculation;
    }

    protected function getIndexValue(string $indexType, Carbon $date, ?string $regionCode = null): ?float
    {
        $index = $this->priceIndexRepository->getForDate($indexType, $date, $regionCode);
        
        return $index ? (float) $index->index_value : null;
    }

    public function bulkApplyIndices(array $itemIds, Carbon $date): int
    {
        $updated = 0;

        EstimateItem::whereIn('id', $itemIds)
            ->whereNotNull('normative_rate_id')
            ->chunk(100, function ($items) use ($date, &$updated) {
                foreach ($items as $item) {
                    $this->applyPriceIndices($item, $date);
                    $item->save();
                    $updated++;
                }
            });

        return $updated;
    }

    public function bulkApplyCoefficients(array $itemIds, array $coefficients): int
    {
        $updated = 0;

        EstimateItem::whereIn('id', $itemIds)
            ->chunk(100, function ($items) use ($coefficients, &$updated) {
                foreach ($items as $item) {
                    $this->applyCoefficients($item, $coefficients);
                    $item->save();
                    $updated++;
                }
            });

        return $updated;
    }
}
