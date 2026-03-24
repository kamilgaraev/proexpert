<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

class EstimatePricingService
{
    public function price(array $workItems): array
    {
        foreach ($workItems as &$workItem) {
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
        }

        return $workItems;
    }
}
