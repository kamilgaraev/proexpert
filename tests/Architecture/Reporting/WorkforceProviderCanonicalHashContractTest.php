<?php

declare(strict_types=1);

namespace Tests\Architecture\Reporting;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkforceProviderCanonicalHashContractTest extends TestCase
{
    #[DataProvider('providerFiles')]
    public function test_provider_finalizes_the_materialized_snapshot_with_canonical_identity(string $file): void
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/'.$file);
        self::assertIsString($source);

        foreach ([
            'ReportSnapshotIdentityBuilder',
            '$this->identities->build(',
            '$this->result($context, $provisional)',
            'materializedSourceHash: $provisional->materializedSourceHash',
        ] as $required) {
            self::assertStringContainsString($required, $source, $file);
        }
    }

    public static function providerFiles(): array
    {
        return [
            'attendance execution' => [
                'app/BusinessModules/Features/WorkforceManagement/Reporting/AttendanceExecutionProvider.php',
            ],
            'workforce capacity' => [
                'app/BusinessModules/Features/WorkforceManagement/Reporting/WorkforceCapacityProvider.php',
            ],
        ];
    }

    public function test_persisted_snapshot_lookup_uses_materialized_source_identity(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3)
            .'/app/BusinessModules/Features/WorkforceManagement/Reporting/Infrastructure/DatabaseWorkforceReportAdapter.php');
        self::assertIsString($source);

        self::assertStringContainsString(
            "->where('source_hash', \$snapshot->materializedSourceHash->value)",
            $source,
        );
    }
}
