<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;

final class WarehouseCustodyExportStorageArchitectureTest extends TestCase
{
    public function test_export_uses_current_object_gateway_and_actor_scope(): void
    {
        $source = file_get_contents(
            __DIR__.'/../../../app/BusinessModules/Features/BasicWarehouse/Services/WarehouseCustodyExportService.php',
        );
        self::assertIsString($source);

        self::assertStringContainsString('OrganizationStoragePath', $source);
        self::assertStringContainsString('CurrentStoredFile', $source);
        self::assertStringContainsString('putPrivate(', $source);
        self::assertStringContainsString('temporaryDownloadUrl(', $source);
        self::assertStringContainsString('user-', $source);
        self::assertStringNotContainsString('->disk()', $source);
        self::assertStringNotContainsString('->temporaryUrl(', $source);

        $controller = file_get_contents(
            __DIR__.'/../../../app/BusinessModules/Features/BasicWarehouse/Controllers/WarehouseCustodyController.php',
        );
        self::assertIsString($controller);
        self::assertStringContainsString('$request->user()->id', $controller);
    }
}
