<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;

final class BudgetEstimateStorageArchitectureTest extends TestCase
{
    public function test_budget_estimate_storage_uses_only_current_object_gateway(): void
    {
        foreach ([
            'app/BusinessModules/Features/BudgetEstimates/Services/EstimateStructureSnapshotStorage.php',
            'app/BusinessModules/Features/BudgetEstimates/Services/Import/FileStorageService.php',
        ] as $relativePath) {
            $source = file_get_contents(__DIR__.'/../../../'.$relativePath);

            self::assertIsString($source);
            self::assertStringContainsString('FileService', $source);
            self::assertStringNotContainsString('Storage::', $source);
            self::assertStringNotContainsString('->disk(', $source);
            self::assertStringNotContainsString("private const DISK = 's3'", $source);
        }
    }

    public function test_import_files_use_streaming_current_object_operations(): void
    {
        $source = file_get_contents(
            __DIR__.'/../../../app/BusinessModules/Features/BudgetEstimates/Services/Import/FileStorageService.php',
        );

        self::assertIsString($source);
        self::assertStringContainsString('putPrivate(', $source);
        self::assertStringContainsString('readCurrent(', $source);
        self::assertStringContainsString('deleteCurrent(', $source);
        self::assertStringContainsString('stream_copy_to_stream(', $source);
        self::assertStringContainsString('withLocalCopy(', $source);
        self::assertStringNotContainsString('file_get_contents(', $source);
    }

    public function test_import_callers_use_managed_local_copies(): void
    {
        foreach ([
            'app/BusinessModules/Features/BudgetEstimates/Services/Import/EstimateImportService.php',
            'app/BusinessModules/Features/BudgetEstimates/Services/Import/ImportPipelineService.php',
            'verify_grandsmeta_mapping.php',
        ] as $relativePath) {
            $source = file_get_contents(__DIR__.'/../../../'.$relativePath);

            self::assertIsString($source);
            self::assertStringContainsString('withLocalCopy(', $source);
            self::assertStringNotContainsString('getAbsolutePath(', $source);
        }
    }

    public function test_file_gateway_exposes_guarded_current_object_existence_check(): void
    {
        $source = file_get_contents(__DIR__.'/../../../app/Services/Storage/FileService.php');

        self::assertIsString($source);
        self::assertStringContainsString('public function existsCurrent(string $key): bool', $source);
        self::assertStringContainsString('$this->assertOrganizationPath($key);', $source);
    }

    public function test_snapshot_job_uses_immutable_organization_key(): void
    {
        $source = file_get_contents(
            __DIR__.'/../../../app/BusinessModules/Features/BudgetEstimates/Jobs/GenerateEstimateSnapshotJob.php',
        );

        self::assertIsString($source);
        self::assertStringContainsString('OrganizationStoragePath::forOrganization(', $source);
        self::assertStringContainsString('Str::uuid()', $source);
        self::assertStringContainsString('putJson(', $source);
        self::assertStringNotContainsString('json_encode($payload', $source);
        self::assertStringNotContainsString("'shared'", $source);
        self::assertStringNotContainsString('$versionTimestamp', $source);
    }
}
