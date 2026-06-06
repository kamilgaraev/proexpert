<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Support\Rules;

use App\BusinessModules\Features\DesignManagement\Enums\DesignCompletenessStatusEnum;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifact;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackageSection;
use App\BusinessModules\Features\DesignManagement\Support\DesignCompletenessRule;
use App\BusinessModules\Features\DesignManagement\Support\DesignCompletenessRuleResult;

final class RequiredSectionRule implements DesignCompletenessRule
{
    public function check(DesignPackage $package): array
    {
        if (!$package->relationLoaded('sections')) {
            return [
                new DesignCompletenessRuleResult(
                    'required_section',
                    DesignCompletenessStatusEnum::BLOCKED,
                    trans_message('design_management.completeness.required_sections_not_generated'),
                    'package',
                    (int) $package->id
                ),
            ];
        }

        if ($package->sections->isEmpty()) {
            return [
                new DesignCompletenessRuleResult(
                    'required_section',
                    DesignCompletenessStatusEnum::BLOCKED,
                    trans_message('design_management.completeness.required_sections_not_generated'),
                    'package',
                    (int) $package->id
                ),
            ];
        }

        return $package->sections
            ->filter(static fn (DesignPackageSection $section): bool => (bool) $section->required)
            ->filter(static function (DesignPackageSection $section): bool {
                if (!$section->relationLoaded('artifacts')) {
                    return true;
                }

                return !$section->artifacts->contains(static fn (DesignArtifact $artifact): bool => $artifact->currentVersion !== null);
            })
            ->map(static fn (DesignPackageSection $section): DesignCompletenessRuleResult => new DesignCompletenessRuleResult(
                'required_section',
                DesignCompletenessStatusEnum::BLOCKED,
                trans_message('design_management.completeness.required_section_missing', ['section' => $section->code]),
                'section',
                (int) $section->id,
                ['section_code' => $section->code]
            ))
            ->values()
            ->all();
    }
}
