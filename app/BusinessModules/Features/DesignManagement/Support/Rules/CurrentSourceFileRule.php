<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Support\Rules;

use App\BusinessModules\Features\DesignManagement\Enums\DesignArtifactTypeEnum;
use App\BusinessModules\Features\DesignManagement\Enums\DesignCompletenessStatusEnum;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Support\DesignCompletenessRule;
use App\BusinessModules\Features\DesignManagement\Support\DesignCompletenessRuleResult;
use App\BusinessModules\Features\DesignManagement\Support\DesignCompletenessScope;

final class CurrentSourceFileRule implements DesignCompletenessRule
{
    public function check(DesignPackage $package): array
    {
        $scope = new DesignCompletenessScope($package);
        foreach ($scope->sections() as $entry) {
            $section = $entry['section'];
            if (($section->metadata['composition_active'] ?? true) === false
                || (int) $section->organization_id !== (int) $package->organization_id
                || (int) $section->project_id !== (int) $package->project_id) {
                continue;
            }
            foreach ($section->artifacts as $artifact) {
                if (! $scope->includesArtifact($artifact, $entry['documents'])
                    || (int) $artifact->package_id !== (int) $package->id
                    || (int) $artifact->section_id !== (int) $section->id
                    || (int) $artifact->organization_id !== (int) $package->organization_id
                    || (int) $artifact->project_id !== (int) $package->project_id
                    || ($artifact->artifact_type === DesignArtifactTypeEnum::MODEL && trim((string) $artifact->document_code) === '')) {
                    continue;
                }
                $version = $artifact->currentVersion;
                if ($version !== null
                    && (int) $version->artifact_id === (int) $artifact->id
                    && (int) $version->organization_id === (int) $package->organization_id
                    && (int) $version->project_id === (int) $package->project_id
                    && trim((string) $version->source_file_path) !== '') {
                    return [];
                }
            }
        }

        return [new DesignCompletenessRuleResult(
            'current_source_file',
            DesignCompletenessStatusEnum::BLOCKED,
            trans_message('design_management.completeness.current_source_file_missing'),
            'package',
            (int) $package->id,
        )];
    }
}
