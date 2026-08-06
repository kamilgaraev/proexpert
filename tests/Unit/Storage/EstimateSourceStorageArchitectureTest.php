<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;

final class EstimateSourceStorageArchitectureTest extends TestCase
{
    public function test_source_storage_uses_one_current_object_gateway_with_org_paths(): void
    {
        $source = $this->source(
            'app/BusinessModules/Addons/EstimateGeneration/Normatives/Services/Storage/EstimateSourceStorageService.php',
        );

        self::assertStringContainsString('FileService', $source);
        self::assertStringContainsString('OrganizationStoragePath', $source);
        self::assertStringContainsString('listCurrent(', $source);
        self::assertStringContainsString('readCurrent(', $source);
        self::assertStringNotContainsString('Storage::', $source);
        self::assertStringNotContainsString('Config::', $source);
        self::assertStringNotContainsString('function disk(', $source);
        self::assertStringNotContainsString('string $bucket', $source);
    }

    public function test_manual_import_requires_organization_and_never_accepts_bucket(): void
    {
        $service = $this->source(
            'app/BusinessModules/Addons/EstimateGeneration/Normatives/Services/Import/EstimateSourceImportService.php',
        );
        $command = $this->source(
            'app/BusinessModules/Addons/EstimateGeneration/Normatives/Console/Commands/ImportEstimateNormativesCommand.php',
        );

        self::assertStringContainsString('public function import(int $organizationId', $service);
        self::assertStringNotContainsString('string $bucket', $service);
        self::assertStringNotContainsString("'bucket' => \$bucket", $service);
        self::assertStringContainsString('{--organization-id=', $command);
        self::assertStringContainsString("preg_match('/^[1-9][0-9]*$/D'", $command);
        self::assertStringNotContainsString('{--bucket=', $command);
    }

    public function test_scheduled_fgiscs_sync_does_not_persist_transient_downloads_to_s3(): void
    {
        foreach ([
            'app/BusinessModules/Addons/EstimateGeneration/Normatives/Services/Fgiscs/FgiscsRegionalPriceUpdateService.php',
            'app/BusinessModules/Addons/EstimateGeneration/Normatives/Services/Fgiscs/FgiscsBuildingResourcePriceUpdateService.php',
            'app/BusinessModules/Addons/EstimateGeneration/Normatives/Services/Fgiscs/FgiscsRegionalPriceSynchronizationService.php',
            'app/BusinessModules/Addons/EstimateGeneration/Normatives/Console/Commands/SyncFgiscsRegionalPricesCommand.php',
            'app/BusinessModules/Addons/EstimateGeneration/Normatives/Console/Commands/SyncFgiscsBuildingResourcePricesCommand.php',
        ] as $relativePath) {
            $source = $this->source($relativePath);

            self::assertStringNotContainsString('EstimateSourceStorageService', $source);
            self::assertStringNotContainsString('storageService->', $source);
            self::assertStringNotContainsString('string $bucket', $source);
            self::assertStringNotContainsString("'bucket' => \$bucket", $source);
            self::assertStringNotContainsString('{--bucket=', $source);
        }

        $regional = $this->source(
            'app/BusinessModules/Addons/EstimateGeneration/Normatives/Services/Fgiscs/FgiscsRegionalPriceUpdateService.php',
        );
        $building = $this->source(
            'app/BusinessModules/Addons/EstimateGeneration/Normatives/Services/Fgiscs/FgiscsBuildingResourcePriceUpdateService.php',
        );

        self::assertStringContainsString('TemporaryEstimateSourceFile::fromContents(', $regional);
        self::assertStringContainsString('TemporaryEstimateSourceFile::fromContents(', $building);
        self::assertStringNotContainsString("tempnam(sys_get_temp_dir(), 'fgiscs-", $regional.$building);
    }

    private function source(string $relativePath): string
    {
        $source = file_get_contents(__DIR__.'/../../../'.$relativePath);
        self::assertIsString($source);

        return $source;
    }
}
