<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\DTOs;

final readonly class KsrResourceDTO
{
    public function __construct(
        public string $code,
        public string $name,
        public ?string $unit = null,
        public ?string $resourceType = null,
        public ?string $group = null,
        public ?array $rawData = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'unit' => $this->unit,
            'resource_type' => $this->resourceType,
            'group' => $this->group,
            'raw_data' => $this->rawData,
        ];
    }
}
