<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;

final class PersonalStorageArchitectureTest extends TestCase
{
    public function test_personal_file_schema_is_reset_to_organization_scoped_current_objects(): void
    {
        $migration = file_get_contents(
            __DIR__.'/../../../database/migrations/2026_08_06_000050_scope_personal_files_to_organizations.php',
        );

        self::assertIsString($migration);
        self::assertStringContainsString("Schema::dropIfExists('personal_files')", $migration);
        self::assertStringContainsString("foreignId('organization_id')", $migration);
        self::assertStringContainsString("string('storage_key', 1024)->nullable()->unique()", $migration);
        self::assertStringContainsString("string('directory', 1024)->default('')", $migration);
        self::assertStringContainsString("string('original_name')", $migration);
        self::assertStringContainsString("string('mime_type')->nullable()", $migration);
        self::assertStringContainsString("char('sha256', 64)->nullable()", $migration);
        self::assertStringContainsString("index(['organization_id', 'user_id', 'is_folder'])", $migration);
    }

    public function test_personal_file_model_exposes_only_new_storage_identity_fields(): void
    {
        $model = file_get_contents(__DIR__.'/../../../app/Models/PersonalFile.php');

        self::assertIsString($model);
        self::assertStringContainsString("'organization_id'", $model);
        self::assertStringContainsString("'storage_key'", $model);
        self::assertStringContainsString("'directory'", $model);
        self::assertStringContainsString("'original_name'", $model);
        self::assertStringContainsString("'mime_type'", $model);
        self::assertStringContainsString("'sha256'", $model);
        self::assertStringNotContainsString("'path'", $model);
        self::assertStringNotContainsString("'filename'", $model);
    }

    public function test_personal_file_controllers_delegate_storage_and_queries_to_service(): void
    {
        foreach ([
            'PersonalFileController.php',
            'ActFileController.php',
        ] as $controller) {
            $source = file_get_contents(
                __DIR__.'/../../../app/Http/Controllers/Api/V1/Admin/'.$controller,
            );

            self::assertIsString($source);
            self::assertStringContainsString('PersonalFileService', $source);
            self::assertStringNotContainsString('PersonalFile::', $source);
            self::assertStringNotContainsString('->disk(', $source);
            self::assertStringNotContainsString('Storage::', $source);
        }
    }

    public function test_personal_file_service_owns_both_organization_and_user_scope(): void
    {
        $service = file_get_contents(__DIR__.'/../../../app/Services/Storage/PersonalFileService.php');

        self::assertIsString($service);
        self::assertStringContainsString("where('organization_id', \$organizationId)", $service);
        self::assertStringContainsString("where('user_id', \$userId)", $service);
        self::assertStringContainsString('OrganizationStoragePath::personal(', $service);
        self::assertStringContainsString('putPrivate(', $service);
        self::assertStringContainsString('temporaryDownloadUrl(', $service);
        self::assertStringContainsString('deleteCurrent(', $service);
        self::assertStringContainsString("where('is_folder', false)", $service);
        self::assertStringContainsString("config('filesystems.s3.download_ttl_seconds'", $service);
        self::assertStringNotContainsString('->disk(', $service);
        self::assertStringNotContainsString('Storage::', $service);
    }

    public function test_reports_use_personal_service_without_legacy_disk_selection_or_retention(): void
    {
        $exporter = file_get_contents(__DIR__.'/../../../app/Services/Export/ExcelExporterService.php');

        self::assertIsString($exporter);
        self::assertStringContainsString('PersonalFileService', $exporter);
        self::assertStringContainsString("'expires_at' => null", $exporter);
        self::assertStringContainsString("now()->format('Y/m/d')", $exporter);
        self::assertStringNotContainsString("date('Y/m/d')", $exporter);
        self::assertStringNotContainsString("string \$disk = 'reports'", $exporter);
        self::assertStringNotContainsString('use Illuminate\\Support\\Str;', $exporter);
        self::assertFileDoesNotExist(
            __DIR__.'/../../../app/Console/Commands/CleanupPersonalFilesCommand.php',
        );
    }
}
