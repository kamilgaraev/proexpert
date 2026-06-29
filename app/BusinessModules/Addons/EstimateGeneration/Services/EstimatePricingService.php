<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

class EstimatePricingService
{
    public function price(array $workItems): array
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
        }

        return $workItems;
    }
}
