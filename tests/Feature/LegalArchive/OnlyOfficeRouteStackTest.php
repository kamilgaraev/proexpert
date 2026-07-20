<?php

declare(strict_types=1);

namespace Tests\Feature\LegalArchive;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class OnlyOfficeRouteStackTest extends TestCase
{
    public function refreshDatabase(): void {}

    public function test_callback_has_only_provider_rate_limit_and_no_admin_wrapper(): void
    {
        $route = Route::getRoutes()->getByName('api.v1.legal-document-editor.callback');
        self::assertNotNull($route);
        self::assertSame('api/v1/legal-document-editor/callback/{session}', $route->uri());
        $middleware = $route->gatherMiddleware();
        self::assertContains(\App\Http\Middleware\OnlyOfficeCallbackBodyLimit::class, $middleware);
        self::assertContains('throttle:legal-editor-callback', $middleware);
        self::assertLessThan(
            array_search('throttle:legal-editor-callback', $middleware, true),
            array_search(\App\Http\Middleware\OnlyOfficeCallbackBodyLimit::class, $middleware, true),
        );
        self::assertNotContains('throttle:api', $middleware);
        self::assertNotContains('admin.response', $middleware);
        self::assertNotContains('auth:api_admin', $middleware);
        self::assertNotContains(\App\Http\Middleware\SetOrganizationContext::class, $middleware);
    }

    public function test_version_routes_use_canonical_contract_and_exact_permissions(): void
    {
        $expected = [
            'admin.legal-archive.document-file-versions.editor.session' => 'authorize:legal_archive.view',
            'admin.legal-archive.document-file-versions.preview' => 'authorize:legal_archive.view',
            'admin.legal-archive.document-file-versions.download' => 'authorize:legal_archive.files.download',
        ];
        foreach ($expected as $name => $permission) {
            $route = Route::getRoutes()->getByName($name);
            self::assertNotNull($route, $name);
            self::assertStringContainsString('document-file-versions/{version}', $route->uri());
            self::assertContains($permission, $route->gatherMiddleware());
        }
    }
}
