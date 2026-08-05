<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;

final class AssistantReportStorageArchitectureTest extends TestCase
{
    public function test_generated_reports_use_personal_storage_gateway(): void
    {
        $service = file_get_contents(
            __DIR__.'/../../../app/BusinessModules/Features/AIAssistant/Services/Reports/AssistantGeneratedReportStorage.php',
        );

        self::assertIsString($service);
        self::assertStringContainsString('PersonalFileService', $service);
        self::assertStringContainsString('storeStream(', $service);
        self::assertStringContainsString('temporaryDownloadUrl(', $service);
        self::assertStringContainsString("'expires_at' => null", $service);
        self::assertStringNotContainsString('Storage::', $service);
        self::assertStringNotContainsString('->disk(', $service);
    }

    public function test_report_tools_do_not_access_s3_directly(): void
    {
        $directory = __DIR__.'/../../../app/BusinessModules/Features/AIAssistant/Actions/Reports/Tools';
        $files = glob($directory.'/Generate*ReportTool.php');

        self::assertIsArray($files);
        self::assertNotEmpty($files);

        foreach ($files as $file) {
            $source = file_get_contents($file);

            self::assertIsString($source);
            self::assertStringNotContainsString('Storage::', $source, $file);
            self::assertStringNotContainsString('OrganizationStoragePath::', $source, $file);
        }
    }

    public function test_pdf_writer_requires_the_report_owner(): void
    {
        $interface = file_get_contents(
            __DIR__.'/../../../app/BusinessModules/Features/AIAssistant/Services/Reports/AssistantReportPdfWriterInterface.php',
        );
        $writer = file_get_contents(
            __DIR__.'/../../../app/BusinessModules/Features/AIAssistant/Services/Reports/DompdfAssistantReportPdfWriter.php',
        );

        self::assertIsString($interface);
        self::assertIsString($writer);
        self::assertStringContainsString('User $user', $interface);
        self::assertStringContainsString('AssistantGeneratedReportStorage', $writer);
        self::assertStringNotContainsString('putContent(', $writer);
        self::assertStringNotContainsString('temporaryUrl(', $writer);
    }

    public function test_report_artifacts_are_current_objects_scoped_to_organization_and_user(): void
    {
        $service = file_get_contents(
            __DIR__.'/../../../app/BusinessModules/Features/AIAssistant/Services/Reports/AssistantReportFileService.php',
        );

        self::assertIsString($service);
        self::assertStringContainsString("'/personal-files/user-'", $service);
        self::assertStringContainsString('temporaryDownloadUrl(', $service);
        self::assertStringContainsString("'expires_at' => null", $service);
        self::assertStringNotContainsString('temporaryUrl(', $service);
    }
}
