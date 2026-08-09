<?php

declare(strict_types=1);

namespace Tests\Architecture\Reporting;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReportRowMaterializedIdentityContractTest extends TestCase
{
    #[Test]
    public function physical_snapshot_readers_use_materialized_identity(): void
    {
        foreach ([
            'app/BusinessModules/Features/QualityControl/Reporting/DefectFlow/Queries/QualityDefectFlowRowQuery.php',
            'app/BusinessModules/Features/SafetyManagement/Reporting/IncidentActions/Queries/SafetyIncidentRowQuery.php',
            'app/BusinessModules/Features/SafetyManagement/Reporting/Admission/Queries/WorkforceAdmissionRowQuery.php',
            'app/BusinessModules/Features/WorkforceManagement/Reporting/Infrastructure/DatabasePayrollReadinessAdapter.php',
            'app/BusinessModules/Features/TimeTracking/Reporting/Infrastructure/DatabaseProjectLaborCostAdapter.php',
            'app/Support/Reporting/EloquentOwnerReportRows.php',
        ] as $file) {
            $source = file_get_contents(base_path($file));
            self::assertIsString($source, $file);
            self::assertStringContainsString(
                "->where('source_hash', \$snapshot->materializedSourceHash->value)",
                $source,
                $file,
            );
            self::assertStringNotContainsString(
                "->where('source_hash', \$snapshot->sourceHash->value)",
                $source,
                $file,
            );
        }
    }
}
