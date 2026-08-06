<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;

final class LegacyStorageRuntimeRemovalTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = dirname(__DIR__, 3);
    }

    public function test_organization_creation_does_not_manage_s3_buckets(): void
    {
        self::assertFileDoesNotExist($this->root.'/app/Services/Storage/OrgBucketService.php');

        foreach ([
            'app/Http/Controllers/Api/V1/Landing/OrganizationController.php',
            'app/Services/Landing/MultiOrganizationService.php',
        ] as $relativePath) {
            $source = $this->source($relativePath);

            self::assertStringNotContainsString('OrgBucketService', $source);
            self::assertStringNotContainsString('createBucket(', $source);
            self::assertStringNotContainsString("'s3_bucket'", $source);
            self::assertStringNotContainsString("'bucket_region'", $source);
        }
    }

    public function test_report_retention_deletion_runtime_is_absent(): void
    {
        foreach ([
            'app/BusinessModules/Core/Reporting/Application/Retention/ExpireReportsService.php',
            'app/BusinessModules/Core/Reporting/Application/Retention/DeleteExpiredReportArtifactsService.php',
            'app/BusinessModules/Core/Reporting/Infrastructure/Console/ExpireReportsCommand.php',
            'app/BusinessModules/Core/Reporting/Infrastructure/Console/DeleteExpiredReportArtifactsCommand.php',
        ] as $relativePath) {
            self::assertFileDoesNotExist($this->root.'/'.$relativePath);
        }

        $routes = $this->source('routes/console.php');
        $provider = $this->source('app/BusinessModules/Core/Reporting/ReportingExecutionServiceProvider.php');

        foreach (['reports:retention:expire', 'reports:retention:delete-artifacts'] as $command) {
            self::assertStringNotContainsString($command, $routes);
        }

        self::assertStringNotContainsString('ExpireReportsCommand', $provider);
        self::assertStringNotContainsString('DeleteExpiredReportArtifactsCommand', $provider);
    }

    public function test_report_access_is_not_limited_by_storage_retention_timestamps(): void
    {
        foreach ([
            'app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportRunStore.php',
            'app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportExportStore.php',
            'app/BusinessModules/Core/Reporting/Application/Exports/ReportExportCoordinator.php',
            'app/BusinessModules/Core/Reporting/Application/Exports/ReportExportExecutionService.php',
            'app/BusinessModules/Core/Reporting/Application/Exports/ReconcileCompletedReportArtifacts.php',
            'app/BusinessModules/Core/Reporting/Application/Actions/Handlers/GetReportRowsHandler.php',
            'app/BusinessModules/Core/Reporting/Application/Actions/Handlers/GetReportDrillDownHandler.php',
        ] as $relativePath) {
            $source = $this->source($relativePath);

            self::assertStringNotContainsString('expiresAt <= $this->clock->now()', $source);
            self::assertStringNotContainsString('expiresAt <= $occurredAt', $source);
            self::assertStringNotContainsString('expired($record->expires_at', $source);
            self::assertStringNotContainsString('expired($parentRun->expires_at', $source);
            self::assertStringNotContainsString('expired($parent->expires_at', $source);
            self::assertStringNotContainsString('remainingSeconds($record->expires_at', $source);
            self::assertStringNotContainsString('remainingSeconds($parent->expires_at', $source);
        }
    }

    public function test_yandex_ai_runtime_is_absent_while_geocoder_remains_independent(): void
    {
        self::assertFileDoesNotExist(
            $this->root.'/app/BusinessModules/Features/AIAssistant/Services/LLM/YandexGPTProvider.php',
        );
        self::assertFileDoesNotExist(
            $this->root.'/app/BusinessModules/Features/AIAssistant/Services/Rag/YandexRagEmbeddingProvider.php',
        );

        foreach ([
            'app/BusinessModules/Features/AIAssistant/AIAssistantServiceProvider.php',
            'app/BusinessModules/Features/AIAssistant/config/ai-assistant.php',
            'app/BusinessModules/Features/AIAssistant/README.md',
            'config/services.php',
        ] as $relativePath) {
            $source = $this->source($relativePath);

            self::assertStringNotContainsString('YandexGPTProvider', $source);
            self::assertStringNotContainsString('YandexRagEmbeddingProvider', $source);
            self::assertStringNotContainsString('YANDEX_API_KEY', $source);
            self::assertStringNotContainsString('YANDEX_FOLDER_ID', $source);
            self::assertStringNotContainsString('YANDEX_MODEL_URI', $source);
            self::assertStringNotContainsString("'yandex' =>", $source);
        }

        self::assertStringContainsString(
            "'yandex'",
            $this->source('config/geocoding.php'),
        );
    }

    public function test_openapi_examples_use_timeweb_storage_urls(): void
    {
        foreach ([
            'docs/openapi/admin/components/schemas/PersonalFile.yaml',
            'docs/openapi/admin/components/schemas/ReportFileItem.yaml',
            'docs/openapi/admin/paths/personal_files.yaml',
            'docs/openapi/admin/paths/report_files.yaml',
            'public/docs/admin/index.html',
        ] as $relativePath) {
            $source = $this->source($relativePath);

            self::assertStringContainsString('https://s3.twcstorage.ru/prohelper-storage/org-', $source);
            self::assertStringNotContainsString('storage.yandexcloud.net', $source);
        }
    }

    private function source(string $relativePath): string
    {
        $source = file_get_contents($this->root.'/'.$relativePath);
        self::assertIsString($source);

        return $source;
    }
}
