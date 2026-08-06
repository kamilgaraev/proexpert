<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;

final class ProductionStorageWriterPathContractTest extends TestCase
{
    public function test_report_legal_and_raster_writers_use_the_shared_actor_path_contract(): void
    {
        $report = $this->source(
            'app/BusinessModules/Core/Reporting/Application/Exports/ReportExportExecutionService.php',
        );
        $reconciliation = $this->source(
            'app/BusinessModules/Core/Reporting/Application/Exports/ReconcileCompletedReportArtifacts.php',
        );
        $legal = $this->source('app/Services/LegalArchive/Signatures/LegalDocumentSignatureService.php');
        $mobile = $this->source('app/Services/Mobile/MobileLegalArchiveService.php');
        $raster = $this->source(
            'app/BusinessModules/Addons/EstimateGeneration/Vision/Preprocessing/RasterPreprocessor.php',
        );

        self::assertStringContainsString('OrganizationStoragePath::forActor(', $report);
        self::assertStringContainsString('OrganizationStoragePath::forActor(', $reconciliation);
        self::assertGreaterThanOrEqual(3, substr_count($legal, 'OrganizationStoragePath::forActor('));
        self::assertStringContainsString('OrganizationStoragePath::forActor(', $mobile);
        self::assertStringContainsString('OrganizationStoragePath::forActor(', $raster);
        self::assertStringNotContainsString('"org-{$request->organization_id}/legal-archive/', $legal);
        self::assertStringNotContainsString('"org-{$organizationId}/legal-archive/', $mobile);
    }

    private function source(string $relativePath): string
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/'.$relativePath);
        self::assertIsString($source);

        return $source;
    }
}
