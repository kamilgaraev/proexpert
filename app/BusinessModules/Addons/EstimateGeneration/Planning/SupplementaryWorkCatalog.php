<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Planning;

final class SupplementaryWorkCatalog
{
    public const VERSION = 'supplementary-work-catalog:v1';

    public function all(): array
    {
        $definitions = [
            'roof.rafters' => ['roof', 'm3'],
            'roof.membrane' => ['roof', 'm2'],
            'roof.battens' => ['roof', 'm2'],
            'roof.fascia' => ['roof', 'm'],
            'roof.bargeboard' => ['roof', 'm'],
            'ceiling.brandering' => ['rough_finishing', 'm2'],
            'ceiling.insulation' => ['rough_finishing', 'm2'],
            'ceiling.cornice' => ['finish_finishing', 'm'],
            'ceiling.hatch' => ['rough_finishing', 'count'],
            'site.apron' => ['site_preparation', 'm2'],
            'sanitary.grab_rails' => ['plumbing', 'count'],
        ];
        $rows = [];
        foreach ($definitions as $work => [$package, $unit]) {
            $rows[$work] = ['work_key' => $work, 'package' => $package,
                'section' => EvaluationSectionMap::forPackage($package), 'suggested_unit' => $unit,
                'name' => trans_message('estimate_composition.works.'.$work),
                'normative_search_text' => trans_message('estimate_composition.search.'.$work)];
        }

        return $rows;
    }
}
