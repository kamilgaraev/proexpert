<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Planning;

final class EvaluationSectionMap
{
    public static function forPackage(string $package): string
    {
        return match ($package) {
            'rough_finishing', 'finish_finishing', 'office_finishing' => 'finishing',
            'walls', 'slabs', 'stairs', 'metal_frame', 'foundation' => 'structures',
            'roof' => 'roofing', 'earthworks' => 'earthworks',
            'electrical', 'lighting', 'low_current', 'server_room' => 'electricity',
            'plumbing', 'water_sewerage' => 'water_supply', 'heating' => 'heating', 'ventilation' => 'ventilation',
            'process_pipelines' => 'technological_pipelines', 'automation' => 'automation',
            'equipment' => 'equipment', default => $package,
        };
    }
}
