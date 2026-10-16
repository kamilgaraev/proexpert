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
            })->map(fn (array $item): DesignCompletenessRuleResult => new DesignCompletenessRuleResult('required_section', DesignCompletenessStatusEnum::BLOCKED, trans_message('design_management.completeness.required_section_missing', ['section' => $this->sectionLabel((string) $item['code'], $item['title'] ?? null, $package->sections->firstWhere('code', (string) $item['code'])?->title)]), 'package', (int) $package->id, ['section_code' => $item['code']]))->values()->all();
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
            ->map(fn (DesignPackageSection $section): DesignCompletenessRuleResult => new DesignCompletenessRuleResult(
                'required_section',
                DesignCompletenessStatusEnum::BLOCKED,
                trans_message('design_management.completeness.required_section_missing', ['section' => $this->sectionLabel((string) $section->code, $section->title)]),
                'section',
                (int) $section->id,
                ['section_code' => $section->code]
            ))
            ->values()
            ->all();
    }

    private function sectionLabel(string $code, mixed ...$titles): string
    {
        foreach ($titles as $title) {
            if (is_string($title) && trim($title) !== '') {
                return trim($title);
            }
        }

        return $code;
    }

}
