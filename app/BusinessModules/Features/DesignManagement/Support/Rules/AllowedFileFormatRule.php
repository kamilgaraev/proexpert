<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Support\Rules;

use App\BusinessModules\Features\DesignManagement\Enums\DesignCompletenessStatusEnum;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifact;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackageSection;
use App\BusinessModules\Features\DesignManagement\Support\DesignCompletenessRule;
use App\BusinessModules\Features\DesignManagement\Support\DesignCompletenessRuleResult;

final class AllowedFileFormatRule implements DesignCompletenessRule
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
                $allowedFormats = is_array($document['allowed_formats'] ?? null) ? $document['allowed_formats'] : [];
                $fileFormat = (string) ($artifact->currentVersion->file_format ?: $artifact->currentVersion->source_format);

                if ($allowedFormats !== [] && !in_array($fileFormat, $allowedFormats, true)) {
                    $results[] = new DesignCompletenessRuleResult(
                        'allowed_file_format',
                        DesignCompletenessStatusEnum::BLOCKED,
                        trans_message('design_management.completeness.file_format_not_allowed', [
                            'document' => $artifact->document_code ?: $artifact->title,
                            'format' => $fileFormat,
                        ]),
                        'artifact',
                        (int) $artifact->id,
                        [
                            'allowed_formats' => $allowedFormats,
                            'file_format' => $fileFormat,
                        ]
                    );
                }
            }
        }

        return $results;
    }
}
