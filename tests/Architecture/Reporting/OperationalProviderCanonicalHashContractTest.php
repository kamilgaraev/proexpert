<?php

declare(strict_types=1);

namespace Tests\Architecture\Reporting;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OperationalProviderCanonicalHashContractTest extends TestCase
{
    #[Test]
    public function operational_providers_preserve_materialized_identity_and_publish_canonical_identity(): void
    {
        foreach ([
            'app/BusinessModules/Features/QualityControl/Reporting/DefectFlow/Providers/QualityDefectFlowReportProvider.php',
            'app/BusinessModules/Features/SafetyManagement/Reporting/IncidentActions/Providers/SafetyIncidentActionsReportProvider.php',
            'app/BusinessModules/Features/SafetyManagement/Reporting/Admission/Providers/WorkforceAdmissionReportProvider.php',
        ] as $file) {
            $source = file_get_contents(base_path($file));
            self::assertIsString($source, $file);
            foreach ([
                'CanonicalReportSourceHashBuilder',
                '$this->identities->build(',
                '$this->result($context, $provisional)',
                'sourceHash: $canonical',
                'materializedSourceHash: $provisional->materializedSourceHash',
            ] as $required) {
                self::assertStringContainsString($required, $source, $file);
            }
            self::assertMatchesRegularExpression(
                "/->where\\('source_hash', \\$[a-z]+->materializedSourceHash->value\\)/",
                $source,
                $file,
            );
        }
    }
}
