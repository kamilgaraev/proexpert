<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\DTOs;

final readonly class FsnbNormResourceDTO
{
    public function __construct(
        public ?string $code,
        public string $name,
        public ?string $unit = null,
        public ?float $quantity = null,
        public ?string $resourceType = null,
        public ?array $rawData = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'unit' => $this->unit,
            'quantity' => $this->quantity,
            'resource_type' => $this->resourceType,
            'raw_data' => $this->rawData,
        ];
    }
}
