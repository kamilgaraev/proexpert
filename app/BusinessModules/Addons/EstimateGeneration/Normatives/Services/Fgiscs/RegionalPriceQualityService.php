<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateRegionalPriceVersion;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateResourcePrice;

class RegionalPriceQualityService
{
    private const BUILDING_RESOURCE_SOURCE_KINDS = [
        'regional_building_resource_export',
        'regional_building_resource_direct',
        'regional_building_resource_index',
    ];

    /**
     * @return array{passed:bool,metrics:array<string,mixed>,errors:array<int,string>}
     */
    public function checkWorkerSalaryVersion(EstimateRegionalPriceVersion $version): array
    {
        $query = EstimateResourcePrice::query()
            ->where('regional_price_version_id', $version->id)
            ->where('source_price_kind', 'regional_worker_salary');

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
            $errors[] = 'Не найдены обязательные коды труда: '.implode(', ', $missingCodes);
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

    /**
     * @return array{passed:bool,metrics:array<string,mixed>,errors:array<int,string>}
     */
    public function checkBuildingResourceVersion(EstimateRegionalPriceVersion $version): array
    {
        $query = EstimateResourcePrice::query()
            ->where('regional_price_version_id', $version->id)
            ->whereIn('source_price_kind', self::BUILDING_RESOURCE_SOURCE_KINDS);
        $count = (clone $query)->count();
        $zeroCount = (clone $query)->where(function ($builder): void {
            $builder->whereNull('base_price')->orWhere('base_price', '<=', 0);
        })->count();
        $errors = [];

        if ($count === 0) {
            $errors[] = 'No building resource prices were imported.';
        }

        if ($zeroCount > 0) {
            $errors[] = 'Building resource prices contain zero values.';
        }

        return [
            'passed' => $errors === [],
            'metrics' => [
                'building_resource_count' => $count,
                'zero_price_count' => $zeroCount,
            ],
            'errors' => $errors,
        ];
    }

    /**
     * @return array{passed:bool,metrics:array<string,mixed>,errors:array<int,string>}
     */
    public function checkCompleteVersion(EstimateRegionalPriceVersion $version, bool $buildingResourcesRequired): array
    {
        $workerSalary = $this->checkWorkerSalaryVersion($version);
        $buildingResources = $buildingResourcesRequired ? $this->checkBuildingResourceVersion($version) : null;
        $errors = array_merge($workerSalary['errors'], $buildingResources['errors'] ?? []);

        return [
            'passed' => $errors === [],
            'metrics' => [
                'worker_salary' => $workerSalary['metrics'],
                'building_resources' => $buildingResources['metrics'] ?? null,
            ],
            'errors' => $errors,
        ];
    }
}
