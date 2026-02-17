<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Calculation;

use App\Models\Estimate;
use Illuminate\Support\Facades\Log;

class EstimateCalculatorService
{
    /**
     * Recalculate all totals for the estimate (Base & Current).
     */
    public function recalculate(Estimate $estimate): void
    {
        $estimate->loadMissing('items');

        $totals = [
            'total_direct_costs' => 0.0,
            'total_base_direct_costs' => 0.0,
            'total_base_materials_cost' => 0.0,
            'total_base_machinery_cost' => 0.0,
            'total_base_labor_cost' => 0.0,
            // We can also aggregate current totals if needed, generally current_total_amount on item includes everything for that item line
        ];

        foreach ($estimate->items as $item) {
            // 1. Current Direct Costs
            // Use current_total_amount if available (it might include small adjustments), 
            // or calculate from unit_price * quantity.
            // Usually current_total_amount is safest if it came from ImportRowMapper's logic.
            $currentTotal = $item->current_total_amount > 0 
                ? $item->current_total_amount 
                : ($item->current_unit_price * $item->quantity);
            
            // Fallback: if current prices are missing, check if unit_price is actually current
            if ($currentTotal <= 0 && $item->unit_price > 0) {
                 $currentTotal = $item->unit_price * $item->quantity;
            }

            $totals['total_direct_costs'] += $currentTotal;

            // 2. Base Direct Costs
            // Only if we have base_unit_price
            if ($item->base_unit_price > 0) {
                $baseTotal = $item->base_unit_price * $item->quantity;
                $totals['total_base_direct_costs'] += $baseTotal;
                
                // Detailed Base Costs
                $totals['total_base_materials_cost'] += ($item->base_materials_cost * $item->quantity);
                $totals['total_base_machinery_cost'] += ($item->base_machinery_cost * $item->quantity);
                $totals['total_base_labor_cost'] += ($item->base_labor_cost * $item->quantity);
            }
        }

        // 3. Overhead & Profit
        // Usually these are calculated as % of Labor (FOT)
        // But in simplified imports, they might be just a % of Direct Costs or fixed values.
        // For now, preserve existing logic or use default rates from Estimate
        $overhead = $estimate->total_overhead_costs; // Keep usage if manual
        $profit = $estimate->total_estimated_profit; // Keep usage if manual

        // If we want to Auto-Calculate based on defaults (e.g. 0% as requested, or user set rates)
        // Let's rely on what's already there or just update Direct Costs.
        // The prompt says "rates to zero", so likely we just sum up direct costs.
        
        // 4. Update Estimate
        $estimate->total_direct_costs = $totals['total_direct_costs'];
        
        $estimate->total_base_direct_costs = $totals['total_base_direct_costs'];
        $estimate->total_base_materials_cost = $totals['total_base_materials_cost'];
        $estimate->total_base_machinery_cost = $totals['total_base_machinery_cost'];
        $estimate->total_base_labor_cost = $totals['total_base_labor_cost'];

        // Recalculate Grand Totals
        // Total Amount = Direct + Overhead + Profit
        $estimate->total_amount = $estimate->total_direct_costs + $estimate->total_overhead_costs + $estimate->total_estimated_profit;
        
        // VAT
        $vatAmount = $estimate->total_amount * ($estimate->vat_rate / 100);
        $estimate->total_amount_with_vat = $estimate->total_amount + $vatAmount;

        $estimate->save();

        Log::info("[EstimateCalculator] Recalculated Estimate #{$estimate->id}: BaseDirect={$estimate->total_base_direct_costs}, CurrentDirect={$estimate->total_direct_costs}");
    }
}
