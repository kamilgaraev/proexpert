<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateRegionalPriceVersion;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateResourcePrice;

class RegionalPriceQualityService
{
    /**
     * @return array{passed:bool,metrics:array<string,mixed>,errors:array<int,string>}
     */
    public function checkWorkerSalaryVersion(EstimateRegionalPriceVersion $version): array
    {
        $query = EstimateResourcePrice::query()
            ->where('regional_price_version_id', $version->id);

        $count = (clone $query)->count();
        $zeroCount = (clone $query)->where(function ($builder): void {
            $builder->whereNull('base_price')->orWhere('base_price', '<=', 0);
        })->count();

        $requiredCodes = ['2-100-02', '2-100-05', '2-100-06', '4-100-040'];
        $foundCodes = (clone $query)
            ->whereIn('resource_code', $requiredCodes)
            ->where('base_price', '>', 0)
            ->pluck('resource_code')
            ->values()
            ->all();

        $missingCodes = array_values(array_diff($requiredCodes, $foundCodes));
        $errors = [];

        if ($count < 8) {
            $errors[] = 'Импортировано слишком мало цен труда.';
        }

        if ($zeroCount > 0) {
            $errors[] = 'В импортированных ценах труда есть нулевые значения.';
        }

        if ($missingCodes !== []) {
            $errors[] = 'Не найдены обязательные коды труда: ' . implode(', ', $missingCodes);
        }

        return [
            'passed' => $errors === [],
            'metrics' => [
                'worker_salary_count' => $count,
                'zero_price_count' => $zeroCount,
                'required_codes_found' => $foundCodes,
                'required_codes_missing' => $missingCodes,
            ],
            'errors' => $errors,
        ];
    }
}
