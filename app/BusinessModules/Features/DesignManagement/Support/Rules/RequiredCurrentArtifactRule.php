<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Support\Rules;

use App\BusinessModules\Features\DesignManagement\Enums\DesignCompletenessStatusEnum;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifact;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackageSection;
use App\BusinessModules\Features\DesignManagement\Support\DesignCompletenessRule;
use App\BusinessModules\Features\DesignManagement\Support\DesignCompletenessRuleResult;

final class RequiredCurrentArtifactRule implements DesignCompletenessRule
{
    public function check(DesignPackage $package): array
    {
        $results = [];

        foreach ($package->sections ?? [] as $section) {
            if (!$section instanceof DesignPackageSection) {
                continue;
            }

            $documents = is_array($section->metadata['documents'] ?? null) ? $section->metadata['documents'] : [];

            foreach ($documents as $document) {
                if (!($document['required'] ?? false)) {
                    continue;
                }

                $documentCode = (string) ($document['document_code'] ?? '');
                $hasCurrent = $section->artifacts
                    ->contains(static fn (DesignArtifact $artifact): bool => $artifact->document_code === $documentCode
                        && $artifact->currentVersion !== null);

                if (!$hasCurrent) {
                    $results[] = new DesignCompletenessRuleResult(
                        'required_current_artifact',
                        DesignCompletenessStatusEnum::BLOCKED,
                        trans_message('design_management.completeness.required_document_missing', [
                            'document' => (string) ($document['document_title'] ?? $documentCode),
                        ]),
                        'section',
                        (int) $section->id,
                        [
                            'section_code' => $section->code,
                            'document_code' => $documentCode,
                        ]
                    );
                }
            }
        }

        return $results;
    }
}
