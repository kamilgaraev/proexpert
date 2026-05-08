<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\DTOs;

final readonly class FsbcPriceDTO
{
    public function __construct(
        public string $collectionType,
        public string $code,
        public string $name,
        public ?string $unit = null,
        public ?float $basePrice = null,
        public ?string $resourceType = null,
        public ?array $rawData = null,
        public ?float $salaryMach = null,
        public ?float $priceCostWithoutSalary = null,
        public ?float $labourMach = null,
        public ?string $driverCode = null,
        public ?string $machinistCategory = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'collection_type' => $this->collectionType,
            'code' => $this->code,
            'name' => $this->name,
            'unit' => $this->unit,
            'base_price' => $this->basePrice,
            'resource_type' => $this->resourceType,
            'raw_data' => $this->rawData,
            'salary_mach' => $this->salaryMach,
            'price_cost_without_salary' => $this->priceCostWithoutSalary,
            'labour_mach' => $this->labourMach,
            'driver_code' => $this->driverCode,
            'machinist_category' => $this->machinistCategory,
        ];
    }
}
