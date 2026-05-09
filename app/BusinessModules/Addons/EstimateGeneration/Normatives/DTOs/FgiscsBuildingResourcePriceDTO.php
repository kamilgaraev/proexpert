<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\DTOs;

final readonly class FgiscsBuildingResourcePriceDTO
{
    public function __construct(
        public string $code,
        public string $name,
        public ?string $unit,
        public float $currentPrice,
        public string $sourcePriceKind,
        public array $rawData = [],
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
            'current_price' => $this->currentPrice,
            'source_price_kind' => $this->sourcePriceKind,
            'raw_data' => $this->rawData,
        ];
    }
}
