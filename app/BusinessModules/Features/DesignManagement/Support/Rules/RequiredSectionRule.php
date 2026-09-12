<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Support\Rules;

use App\BusinessModules\Features\DesignManagement\Enums\DesignCompletenessStatusEnum;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifact;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackageSection;
use App\BusinessModules\Features\DesignManagement\Support\DesignCompletenessScope;
use App\BusinessModules\Features\DesignManagement\Support\DesignCompletenessRule;
use App\BusinessModules\Features\DesignManagement\Support\DesignCompletenessRuleResult;

final class RequiredSectionRule implements DesignCompletenessRule
{
    public function check(DesignPackage $package): array
    {
        $scope = new DesignCompletenessScope($package);
        $selected = $scope->selectedItems();
        if ($selected !== null) {
            $artifacts = $scope->artifacts();
            return collect($selected)->filter(static fn (array $item): bool => (bool) ($item['required'] ?? true))->filter(function (array $item) use ($package, $artifacts): bool {
                $section = $package->sections->firstWhere('code', (string) $item['code']);
                return ! $section instanceof DesignPackageSection || ! $artifacts->contains(static fn (DesignArtifact $artifact): bool => $artifact->section_id === $section->id && $artifact->currentVersion !== null);
            })->map(static fn (array $item): DesignCompletenessRuleResult => new DesignCompletenessRuleResult('required_section', DesignCompletenessStatusEnum::BLOCKED, trans_message('design_management.completeness.required_section_missing', ['section' => $item['code']]), 'package', (int) $package->id, ['section_code' => $item['code']]))->values()->all();
        }
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
