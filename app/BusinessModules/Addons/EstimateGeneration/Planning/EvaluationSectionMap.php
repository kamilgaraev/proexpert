<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Planning;

final class EvaluationSectionMap
{
    public static function forPackage(string $package): string
    {
        if (str_starts_with($package, 'ai-composer-supplementary:')) {
            $section = substr($package, strlen('ai-composer-supplementary:'));
            if (in_array($section, ['structures', 'finishing', 'roofing', 'earthworks', 'water_supply', 'sewerage', 'heating', 'ventilation',
                'electricity', 'automation', 'technological_pipelines', 'equipment'], true)) {
                return $section;
            }
        }

        return match ($package) {
            'rough_finishing', 'finish_finishing', 'office_finishing', 'custom-finishing' => 'finishing',
            'walls', 'slabs', 'stairs', 'metal_frame', 'foundation', 'foundations', 'industrial_floor', 'envelope', 'gates', 'openings', 'custom-walls', 'custom-openings', 'custom-foundation' => 'structures',
            'roof', 'custom-roof' => 'roofing', 'earthworks', 'custom-earthworks' => 'earthworks',
            'electrical', 'power_supply', 'lighting', 'low_current', 'server_room', 'custom-electrical' => 'electricity',
            'plumbing', 'water_sewerage', 'custom-plumbing' => 'water_supply', 'custom-sewerage' => 'sewerage',
            'heating', 'custom-heating' => 'heating', 'ventilation', 'custom-ventilation' => 'ventilation',
            'process_pipelines' => 'technological_pipelines', 'automation' => 'automation',
            'equipment', 'commissioning' => 'equipment', 'site_preparation' => 'earthworks', default => $package,
        };
    }
}
