<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Support\Rules;

use App\BusinessModules\Features\DesignManagement\Enums\DesignCompletenessStatusEnum;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifact;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackageSection;
use App\BusinessModules\Features\DesignManagement\Support\DesignCompletenessRule;
use App\BusinessModules\Features\DesignManagement\Support\DesignCompletenessRuleResult;

final class SheetRegistryRule implements DesignCompletenessRule
{
    public function check(DesignPackage $package): array
    {
        $results = [];

        foreach ($package->sections ?? [] as $section) {
            if (!$section instanceof DesignPackageSection) {
                continue;
            }

            $documents = collect($section->metadata['documents'] ?? [])->keyBy('document_code');

            foreach ($section->artifacts ?? [] as $artifact) {
                if (!$artifact instanceof DesignArtifact || $artifact->currentVersion === null) {
                    continue;
                }

                $document = $documents->get($artifact->document_code);
                $requiresSheets = (bool) $artifact->requires_sheet_registry
                    || (bool) ($document['sheet_registry_required'] ?? false);

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
