<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use PHPUnit\Framework\TestCase;

final class LegalArchiveAccessBoundaryTest extends TestCase
{
    public function test_routes_allow_external_discovery_to_reach_object_authorization(): void
    {
        $routes = file_get_contents(__DIR__.'/../../../routes/api/v1/admin/legal_archive.php');

        self::assertIsString($routes);
        self::assertStringContainsString("Route::get('documents', [LegalArchiveController::class, 'index'])\n        ->name", $routes);
        self::assertStringContainsString("Route::get('documents/{document}', [LegalArchiveController::class, 'show'])\n        ->name", $routes);
        self::assertStringContainsString("Route::get('documents/{document}/current-version', [LegalArchiveController::class, 'currentVersion'])\n        ->name", $routes);
        self::assertStringNotContainsString("Route::get('documents', [LegalArchiveController::class, 'index'])\n        ->middleware('authorize:legal_archive.view')", $routes);
    }

    public function test_mutation_boundaries_require_exact_permissions(): void
    {
        $routes = file_get_contents(__DIR__.'/../../../routes/api/v1/admin/legal_archive.php');
        $controller = file_get_contents(__DIR__.'/../../../app/Http/Controllers/Api/V1/Admin/LegalArchiveController.php');
        $indexRequest = file_get_contents(__DIR__.'/../../../app/Http/Requests/Api/V1/Admin/LegalArchive/LegalArchiveDocumentIndexRequest.php');
        $registry = file_get_contents(__DIR__.'/../../../app/Services/LegalArchive/LegalArchiveRegistryService.php');

        foreach ([$routes, $controller, $indexRequest, $registry] as $source) {
            self::assertIsString($source);
        }

        self::assertStringContainsString("->middleware(['authorize:legal_archive.versions.create', 'authorize:legal_archive.files.upload'])", $routes);
        self::assertStringContainsString("authorizePermission(\$actor, \$found, 'legal_archive.update')", $controller);
        self::assertStringContainsString("authorizePermission(\$actor, \$found, 'legal_archive.versions.create')", $controller);
        self::assertStringContainsString("authorizePermission(\$actor, \$found, 'legal_archive.files.upload')", $controller);
        self::assertStringNotContainsString('AuthorizationService', $indexRequest);
        self::assertStringContainsString('scopeAccessibleQuery($query, $actor, $organizationId)', $registry);
    }
}
