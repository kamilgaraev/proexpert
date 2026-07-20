<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTOs\FgiscsBuildingResourcePriceDTO;

class FgiscsBuildingResourcePricePriority
{
    private const PRIORITIES = [
        'regional_building_resource_index' => 10,
        'regional_building_resource_export' => 20,
        'regional_building_resource_direct' => 30,
    ];

    public function preferred(
        ?FgiscsBuildingResourcePriceDTO $current,
        FgiscsBuildingResourcePriceDTO $candidate,
    ): FgiscsBuildingResourcePriceDTO {
        if ($current === null || $this->priority($candidate) > $this->priority($current)) {
            return $candidate;
        }

        return $current;
    }

    private function priority(FgiscsBuildingResourcePriceDTO $price): int
    {
        return self::PRIORITIES[$price->sourcePriceKind] ?? 0;
    }
}
