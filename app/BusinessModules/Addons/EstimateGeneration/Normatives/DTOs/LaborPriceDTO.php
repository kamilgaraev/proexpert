<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\DTOs;

final readonly class LaborPriceDTO
{
    public function __construct(
        public string $code,
        public string $name,
        public ?string $unit,
        public float $basePrice,
        public string $resourceType,
        public ?array $rawData = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'unit' => $this->unit,
            'base_price' => $this->basePrice,
            'resource_type' => $this->resourceType,
            'raw_data' => $this->rawData,
        ];
    }
}
