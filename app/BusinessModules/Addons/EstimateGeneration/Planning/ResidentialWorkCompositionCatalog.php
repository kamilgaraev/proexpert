<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Planning;

use App\BusinessModules\Addons\EstimateGeneration\Services\ObjectTypeSignalClassifier;

final class ResidentialWorkCompositionCatalog
{
    public const VERSION = 'residential_work_composition:v4';

    /** @return array<string, list<string>> */
    public function requirements(array $draft): array
    {
        $profile = is_array($draft['object_profile'] ?? null) ? $draft['object_profile'] : [];
        if (! ObjectTypeSignalClassifier::isResidential((string) ($profile['object_type'] ?? ''))) {
            return [];
        }

        $floors = is_numeric($profile['floors'] ?? null) ? (int) $profile['floors'] : null;
        $signals = is_array($profile['planning_signals'] ?? null) ? $profile['planning_signals'] : [];
        $dimensions = is_array($profile['dimensions'] ?? null) ? $profile['dimensions'] : [];
        $roofType = (string) ($signals['roof_type'] ?? $dimensions['roof_type'] ?? '');

        return [
            'earthworks' => ['earth.trench', 'earth.backfill', 'earth.plan'],
            'foundation' => [
                'foundation.prep', 'foundation.formwork', 'foundation.rebar',
                'foundation.concrete', 'foundation.waterproofing',
            ],
            'walls' => ['walls.external_volume', 'walls.internal', 'walls.lintels'],
            'slabs' => $floors !== null && $floors < 2 ? [] : ['slabs.formwork', 'slabs.concrete', 'slabs.rebar'],
            'stairs' => $floors !== null && $floors < 2
                ? []
                : ['stairs.flights', 'stairs.landings', 'stairs.railings'],
            'roof' => match ($roofType) {
                'flat' => [
                    'roof.flat.base', 'roof.flat.vapor_barrier',
                    'roof.flat.insulation', 'roof.flat.waterproofing',
                ],
                'pitched' => [
                    'roof.rafters', 'roof.vapor_barrier', 'roof.insulation',
                    'roof.membrane', 'roof.battens', 'roof.covering', 'roof.gutter',
                ],
                default => ['roof.area'],
            },
            'openings' => ['openings.windows', 'openings.doors'],
            'facade' => ['facade.area'],
            'electrical' => [
                'electrical.main_cable', 'electrical.power_lines',
                'electrical.panel', 'electrical.outlets', 'electrical.switches',
                'electrical.grounding',
            ],
            'lighting' => ['lighting.lines', 'lighting.fixtures'],
            'plumbing' => [
                'plumbing.pipe', 'sanitary.showers', 'sanitary.toilets', 'sanitary.washbasins',
                'sanitary.waterproofing', 'sanitary.tile',
            ],
            'sewerage' => ['sewerage.pipe', 'sewerage.risers', 'sewerage.revisions'],
            'heating' => ['heating.pipe', 'heating.radiators'],
            'ventilation' => ['ventilation.air_exchange'],
            'rough_finishing' => ['rough.floor', 'rough.walls', 'rough.ceiling'],
            'finish_finishing' => ['finish.floor', 'finish.paint', 'finish.ceiling', 'finish.baseboard'],
        ];
    }
}
