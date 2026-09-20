<?php

declare(strict_types=1);

namespace Tests\Unit\ExecutiveDocumentation;

use PHPUnit\Framework\TestCase;

final class ExecutiveDocumentRevisionContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 3);
    }

    public function test_revision_schema_keeps_file_snapshot_hash_and_operation_identity(): void
    {
        $migration = file_get_contents($this->root.'/app/BusinessModules/Features/ExecutiveDocumentation/migrations/2026_09_20_010001_harden_executive_document_revisions.php');

        self::assertIsString($migration);
        self::assertStringContainsString("content_hash", $migration);
        self::assertStringContainsString("profile_snapshot", $migration);
        self::assertStringContainsString("basis_snapshot", $migration);
        self::assertStringContainsString("operation_key", $migration);
        self::assertStringContainsString("version_id", $migration);
    }

    public function test_routes_expose_draft_patch_and_new_version_endpoint(): void
    {
        $routes = file_get_contents($this->root.'/app/BusinessModules/Features/ExecutiveDocumentation/routes.php');

        self::assertIsString($routes);
        self::assertStringContainsString("Route::patch('/documents/{id}'", $routes);
        self::assertStringContainsString("Route::post('/documents/{id}/versions'", $routes);
    }

    public function test_service_does_not_mutate_an_existing_revision_when_creating_correction(): void
    {
        $service = file_get_contents($this->root.'/app/BusinessModules/Features/ExecutiveDocumentation/Services/ExecutiveDocumentationService.php');

        self::assertIsString($service);
        self::assertStringContainsString("'profile_snapshot' =>", $service);
        self::assertStringContainsString("'content_hash' =>", $service);
        self::assertStringContainsString("$version->update(['status' => 'approved'", $service);
        self::assertStringContainsString("$remark->version", $service);
    }
}
