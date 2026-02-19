<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services;

use App\Models\Estimate;
use App\Models\EstimateItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WhatIfSimulatorService
{
    public function simulate(int $estimateId, array $overrides): array
    {
        $estimate = Estimate::with(['sections.items.measurementUnit'])->findOrFail($estimateId);

        $materialsIndex = (float)($overrides['materials_index'] ?? 1.0);
        $machineryIndex = (float)($overrides['machinery_index'] ?? 1.0);
        $laborIndex     = (float)($overrides['labor_index']     ?? 1.0);
        $globalIndex    = (float)($overrides['global_index']    ?? 1.0);
        $vatRate        = (float)($overrides['vat_rate']        ?? $estimate->vat_rate ?? 20);
        $overheadRate   = (float)($overrides['overhead_rate']   ?? $estimate->overhead_rate ?? 15);
        $profitRate     = (float)($overrides['profit_rate']     ?? $estimate->profit_rate ?? 12);

        $sections = [];
        $totalDirect = 0.0;

        foreach ($estimate->sections as $section) {
            $sectionTotal = 0.0;
            $items = [];

            foreach ($section->items as $item) {
                $qty = (float)($item->quantity ?? 1);

                $currentPrice = match ($item->item_type) {
                    'material'  => ($item->base_unit_price ?? $item->unit_price ?? 0) * $materialsIndex * $globalIndex,
                    'machinery' => ($item->base_unit_price ?? $item->unit_price ?? 0) * $machineryIndex * $globalIndex,
                    'labor'     => ($item->base_unit_price ?? $item->unit_price ?? 0) * $laborIndex * $globalIndex,
                    default     => ($item->base_unit_price ?? $item->unit_price ?? 0) * $globalIndex,
                };

                $itemTotal = round($qty * $currentPrice, 2);
                $sectionTotal += $itemTotal;

                $items[] = [
                    'id'                => $item->id,
                    'name'              => $item->name,
                    'item_type'         => $item->item_type,
                    'quantity'          => $qty,
                    'base_unit_price'   => $item->base_unit_price ?? $item->unit_price,
                    'simulated_price'   => round($currentPrice, 2),
                    'simulated_total'   => $itemTotal,
                    'original_total'    => (float)($item->total_amount ?? $item->current_total_amount ?? 0),
                    'delta'             => $itemTotal - (float)($item->total_amount ?? $item->current_total_amount ?? 0),
                ];
            }

            $sections[] = [
                'id'    => $section->id,
                'name'  => $section->name,
                'total' => round($sectionTotal, 2),
                'items' => $items,
            ];

            $totalDirect += $sectionTotal;
        }

        $overhead     = round($totalDirect * $overheadRate / 100, 2);
        $profit       = round($totalDirect * $profitRate / 100, 2);
        $subtotal     = round($totalDirect + $overhead + $profit, 2);
        $vat          = round($subtotal * $vatRate / 100, 2);
        $totalWithVat = round($subtotal + $vat, 2);

        $originalTotal = (float)$estimate->total_amount_with_vat;
        $deltaTotal    = round($totalWithVat - $originalTotal, 2);

        Log::info("[WhatIf] Simulated estimate {$estimateId}: original={$originalTotal}, simulated={$totalWithVat}");

        return [
            'estimate_id'   => $estimateId,
            'overrides'     => $overrides,
            'sections'      => $sections,
            'totals'        => [
                'direct_costs'       => round($totalDirect, 2),
                'overhead'           => $overhead,
                'profit'             => $profit,
                'subtotal'           => $subtotal,
                'vat'                => $vat,
                'total_with_vat'     => $totalWithVat,
                'original_total'     => $originalTotal,
                'delta'              => $deltaTotal,
                'delta_pct'          => $originalTotal > 0
                    ? round($deltaTotal / $originalTotal * 100, 2)
                    : null,
            ],
            'applied_indices' => [
                'materials_index' => $materialsIndex,
                'machinery_index' => $machineryIndex,
                'labor_index'     => $laborIndex,
                'global_index'    => $globalIndex,
                'vat_rate'        => $vatRate,
                'overhead_rate'   => $overheadRate,
                'profit_rate'     => $profitRate,
            ],
        ];
    }
}
