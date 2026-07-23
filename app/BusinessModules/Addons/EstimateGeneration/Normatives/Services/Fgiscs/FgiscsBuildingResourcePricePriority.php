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
        if ($current === null || $this->priority($candidate->sourcePriceKind) > $this->priority($current->sourcePriceKind)) {
            return $candidate;
        }

        return $current;
    }

    public function shouldReplace(string $currentSourcePriceKind, FgiscsBuildingResourcePriceDTO $candidate): bool
    {
        return $this->priority($candidate->sourcePriceKind) >= $this->priority($currentSourcePriceKind);
    }

    private function priority(string $sourcePriceKind): int
    {
        return self::PRIORITIES[$sourcePriceKind] ?? 0;
    }
}
