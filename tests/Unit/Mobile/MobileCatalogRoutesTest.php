<?php

declare(strict_types=1);

namespace Tests\Unit\Mobile;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class MobileCatalogRoutesTest extends TestCase
{
    public function test_crm_catalog_routes_use_mobile_auth_and_allow_uuid_identifiers(): void
    {
        $index = Route::getRoutes()->getByName('api.v1.mobile.catalog.crm.index');
        $show = Route::getRoutes()->getByName('api.v1.mobile.catalog.crm.show');
        $storeActivity = Route::getRoutes()->getByName('api.v1.mobile.catalog.crm.activities.store');

        $this->assertNotNull($index);
        $this->assertNotNull($show);
        $this->assertNotNull($storeActivity);
        $this->assertStringContainsString('/catalog/crm/{entity}/{id}', $show->uri());
        $this->assertContains('POST', $storeActivity->methods());
        $this->assertMobileMiddleware($index->gatherMiddleware());
        $this->assertMobileMiddleware($show->gatherMiddleware());
        $this->assertMobileMiddleware($storeActivity->gatherMiddleware());
    }

    public function test_tender_catalog_routes_use_mobile_auth_and_uuid_identifiers(): void
    {
        $index = Route::getRoutes()->getByName('api.v1.mobile.catalog.tenders.index');
        $show = Route::getRoutes()->getByName('api.v1.mobile.catalog.tenders.show');

        $this->assertNotNull($index);
        $this->assertNotNull($show);
        $this->assertStringContainsString('/catalog/tenders/{id}', $show->uri());
        $this->assertMobileMiddleware($index->gatherMiddleware());
        $this->assertMobileMiddleware($show->gatherMiddleware());
    }

    public function test_mobile_purchase_request_create_route_requires_create_permission(): void
    {
        $route = Route::getRoutes()->getByName('mobile.procurement.purchase_requests.store');

        $this->assertNotNull($route);
        $this->assertContains('POST', $route->methods());
        $this->assertContains('authorize:procurement.purchase_requests.create', $route->gatherMiddleware());
        $this->assertContains('procurement.active', $route->gatherMiddleware());
    }

    private function assertMobileMiddleware(array $middleware): void
    {
        $this->assertContains('auth:api_mobile', $middleware);
        $this->assertContains('auth.jwt:api_mobile', $middleware);
        $this->assertContains('organization.context', $middleware);
        $this->assertContains('can:access-mobile-app', $middleware);
    }
}
