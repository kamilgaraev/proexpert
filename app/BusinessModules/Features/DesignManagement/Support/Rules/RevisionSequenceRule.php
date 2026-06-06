<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Support\Rules;

use App\BusinessModules\Features\DesignManagement\Enums\DesignCompletenessStatusEnum;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifact;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Support\DesignCompletenessRule;
use App\BusinessModules\Features\DesignManagement\Support\DesignCompletenessRuleResult;

final class RevisionSequenceRule implements DesignCompletenessRule
{
    public function check(DesignPackage $package): array
    {
        $results = [];

        foreach ($package->artifacts ?? [] as $artifact) {
            if (!$artifact instanceof DesignArtifact || $artifact->currentVersion === null) {
                continue;
            }

            $revision = $artifact->currentVersion->revision_label ?: $artifact->currentVersion->revision;

            if ($revision === null || trim((string) $revision) === '') {
                $results[] = new DesignCompletenessRuleResult(
                    'revision_sequence',
                    DesignCompletenessStatusEnum::WARNING,
                    trans_message('design_management.completeness.revision_missing', [
                        'document' => $artifact->document_code ?: $artifact->title,
                    ]),
                    'version',
                    (int) $artifact->currentVersion->id
                );
            }
        }

        return $results;
    }
}
