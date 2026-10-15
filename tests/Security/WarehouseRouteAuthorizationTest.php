<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Services\Entitlements\OrganizationEntitlementService;
use Mockery;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class WarehouseRouteAuthorizationTest extends TestCase
{
    public function test_request_cancellation_releases_only_reservations_bound_to_that_request_and_organization(): void
    {
        \Illuminate\Support\Facades\Event::fake([\App\BusinessModules\Features\SiteRequests\Events\SiteRequestStatusChanged::class]);
        $access = Mockery::mock(\App\Modules\Core\AccessController::class);
        $access->shouldReceive('hasModuleAccess')->andReturn(true);
        $this->app->instance(\App\Modules\Core\AccessController::class, $access);
        foreach (['foreign_organization', 'different_request', 'legitimate'] as $scenario) {
            $context = AdminApiTestContext::create();
            $reservationContext = $scenario === 'foreign_organization' ? AdminApiTestContext::create() : $context;
            $project = \App\Models\Project::factory()->create(['organization_id' => $context->organization->id]);
            $request = \App\BusinessModules\Features\SiteRequests\Models\SiteRequest::query()->create([
                'organization_id' => $context->organization->id, 'project_id' => $project->id,
                'user_id' => $context->user->id, 'title' => 'Reservation security',
                'request_type' => 'material_request', 'status' => 'pending', 'priority' => 'medium',
                'material_name' => 'Cable', 'material_quantity' => 5, 'material_unit' => 'm',
            ]);
            $unit = \App\Models\MeasurementUnit::query()->where('organization_id', $reservationContext->organization->id)->firstOrFail();
            $material = \App\Models\Material::query()->create([
                'organization_id' => $reservationContext->organization->id, 'measurement_unit_id' => $unit->id,
                'name' => 'Cable', 'code' => 'SEC-CABLE', 'is_active' => true,
            ]);
            $warehouse = \App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse::query()->create([
                'organization_id' => $reservationContext->organization->id, 'name' => 'Security warehouse',
                'code' => 'SEC', 'warehouse_type' => 'central', 'is_main' => true, 'is_active' => true,
            ]);
            $balance = \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance::query()->create([
                'organization_id' => $reservationContext->organization->id, 'warehouse_id' => $warehouse->id,
                'material_id' => $material->id, 'available_quantity' => 0, 'reserved_quantity' => 5,
                'average_price' => 100, 'unit_price' => 100,
            ]);
            $reservation = \App\BusinessModules\Features\BasicWarehouse\Models\AssetReservation::query()->create([
                'organization_id' => $reservationContext->organization->id, 'warehouse_id' => $warehouse->id,
                'material_id' => $material->id, 'quantity' => 5, 'reserved_by' => $reservationContext->user->id,
                'status' => 'active', 'reserved_at' => now(), 'expires_at' => now()->addDay(),
                'metadata' => ['site_request_id' => $scenario === 'different_request' ? $request->id + 1000 : $request->id],
            ]);
            $request->update(['metadata' => [
                'material_reserved' => $scenario !== 'legitimate', 'asset_reservation_id' => $reservation->id,
                'reserved_warehouse_id' => $warehouse->id, 'reserved_quantity' => 5,
            ]]);
            app(\App\BusinessModules\Features\SiteRequests\Services\SiteRequestService::class)->cancel($request, $context->user->id);
            self::assertSame('cancelled', $request->fresh()->status->value);
            self::assertSame($scenario === 'legitimate' ? 'cancelled' : 'active', $reservation->fresh()->status);
            self::assertSame($scenario === 'legitimate' ? 0.0 : 5.0, (float) $balance->fresh()->reserved_quantity);
        }
    }

    public function test_stock_mutations_require_warehouse_permissions_before_validation_or_resource_lookup(): void
    {
        $entitlements = Mockery::mock(OrganizationEntitlementService::class);
        $entitlements->shouldReceive('getEffectiveModules')->andReturn(collect([new \App\Models\Module(['slug' => 'basic-warehouse'])]));
        $this->app->instance(OrganizationEntitlementService::class, $entitlements);
        $viewer = AdminApiTestContext::create(roleSlug: 'admin_viewer');
        foreach ([
            ['POST', '/api/v1/admin/warehouses/operations/reserve'],
            ['POST', '/api/v1/admin/warehouses/operations/unreserve'],
            ['POST', '/api/v1/admin/advanced-warehouse/reservations'],
            ['DELETE', '/api/v1/admin/advanced-warehouse/reservations/1'],
            ['PUT', '/api/v1/admin/warehouses/1'],
            ['DELETE', '/api/v1/admin/warehouses/1'],
        ] as [$method, $uri]) {
            $this->withHeaders($viewer->authHeaders())->json($method, $uri, [])->assertForbidden();
        }
        $manager = AdminApiTestContext::create();
        UserRoleAssignment::assignRole($manager->user, 'organization_owner', AuthorizationContext::getOrganizationContext($manager->organization->id));
        $this->withHeaders($manager->authHeaders())->postJson('/api/v1/admin/warehouses/operations/reserve', [])->assertUnprocessable();
        $this->withHeaders($manager->authHeaders())->postJson('/api/v1/admin/advanced-warehouse/reservations', [])->assertUnprocessable();
    }
}
