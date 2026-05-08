<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\DTOs;

final readonly class FgiscsPricePeriodDTO
{
    public function __construct(
        public int $id,
        public string $name,
        public int $year,
        public int $quarter,
    ) {
    }
}
