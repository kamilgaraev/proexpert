<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Support\Rules;

use App\BusinessModules\Features\DesignManagement\Enums\DesignCompletenessStatusEnum;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifact;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Support\DesignCompletenessScope;
use App\BusinessModules\Features\DesignManagement\Support\DesignCompletenessRule;
use App\BusinessModules\Features\DesignManagement\Support\DesignCompletenessRuleResult;

final class SheetRegistryRule implements DesignCompletenessRule
{
    public function check(DesignPackage $package): array
    {
        $results = [];

        $scope = new DesignCompletenessScope($package);
        foreach ($scope->sections() as $entry) {
            $section = $entry['section'];
            $documents = $entry['documents'];

            foreach ($section->artifacts ?? [] as $artifact) {
                if (!$artifact instanceof DesignArtifact || $artifact->currentVersion === null || ! $scope->includesArtifact($artifact, $documents)) {
                    continue;
                }

                $document = $documents->get($artifact->document_code);
                $requiresSheets = $scope->selectedItems() !== null && is_array($document) && array_key_exists('sheet_registry_required', $document)
                    ? (bool) $document['sheet_registry_required']
                    : (bool) $artifact->requires_sheet_registry || (bool) ($document['sheet_registry_required'] ?? false);

                if (!$requiresSheets) {
                    continue;
                }

                $version = $artifact->currentVersion;
                $sheetCount = $version->relationLoaded('sheets') ? $version->sheets->count() : (int) ($version->sheet_count ?? 0);

                if ($sheetCount === 0) {
                    $results[] = new DesignCompletenessRuleResult(
                        'sheet_registry',
                        DesignCompletenessStatusEnum::BLOCKED,
                        trans_message('design_management.completeness.sheet_registry_missing', [
                            'document' => $artifact->document_code ?: $artifact->title,
                        ]),
                        'version',
                        (int) $version->id
                    );
                    continue;
                }

                if ($version->page_count !== null && (int) $version->page_count !== $sheetCount) {
                    $results[] = new DesignCompletenessRuleResult(
                        'sheet_registry',
                        DesignCompletenessStatusEnum::WARNING,
                        trans_message('design_management.completeness.sheet_registry_page_mismatch', [
                            'document' => $artifact->document_code ?: $artifact->title,
                        ]),
                        'version',
                        (int) $version->id,
                        [
                            'page_count' => (int) $version->page_count,
                            'sheet_count' => $sheetCount,
                        ]
                    );
                }
            }
        }

        return $results;
    }
}
