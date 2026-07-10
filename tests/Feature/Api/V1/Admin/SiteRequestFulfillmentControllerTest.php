<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequestLine;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestStatusEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestTypeEnum;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Material;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class SiteRequestFulfillmentControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_material_options_are_purchase_only(): void
    {
        $context = AdminApiTestContext::create();
        $siteRequest = $this->createMaterialRequest($context->organization, $context->user, [
            'material_id' => null,
            'material_name' => 'Монтажная пена',
            'material_quantity' => 12.5,
            'material_unit' => 'баллон',
        ]);
        $this->allowFulfillmentAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/site-requests/{$siteRequest->id}/fulfillment-options");

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.material_source', 'manual');
        $response->assertJsonPath('data.warehouse_lookup_supported', false);
        $response->assertJsonPath('data.warehouse_unavailable_reason', 'Для материала, введенного вручную, доступна только закупка.');
        $response->assertJsonPath('data.can_use_warehouse', false);
        $response->assertJsonPath('data.can_use_purchase', true);
        $response->assertJsonPath('data.can_use_mixed', false);
        $response->assertJsonPath('data.recommended_source', 'purchase');
        $response->assertJsonPath('data.warehouses', []);
        $response->assertJsonPath('data.request.material_id', null);
        $response->assertJsonPath('data.request.material_name', 'Монтажная пена');
        $response->assertJsonPath('data.request.requested_quantity', 12.5);
        $response->assertJsonPath('data.request.unit', 'баллон');
    }

    public function test_manual_purchase_creates_exactly_one_purchase_request_and_preserves_line_values(): void
    {
        $context = AdminApiTestContext::create();
        $siteRequest = $this->createMaterialRequest($context->organization, $context->user, [
            'material_id' => null,
            'material_name' => 'Монтажная пена',
            'material_quantity' => 12.5,
            'material_unit' => 'баллон',
        ]);
        $this->allowFulfillmentAccess();

        $firstResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/site-requests/{$siteRequest->id}/fulfillment-decision", [
                'source' => 'purchase',
            ]);
        $secondResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/site-requests/{$siteRequest->id}/fulfillment-decision", [
                'source' => 'purchase',
            ]);

        $firstResponse->assertOk();
        $secondResponse->assertOk();
        $purchaseRequestId = $firstResponse->json('data.decision.purchase_request_id');

        $this->assertSame($purchaseRequestId, $secondResponse->json('data.decision.purchase_request_id'));
        $this->assertSame(1, PurchaseRequest::query()
            ->where('organization_id', $context->organization->id)
            ->where('site_request_id', $siteRequest->id)
            ->count());
        $this->assertDatabaseHas('purchase_request_lines', [
            'purchase_request_id' => $purchaseRequestId,
            'material_id' => null,
            'name' => 'Монтажная пена',
            'quantity' => 12.5,
            'unit' => 'баллон',
        ]);
    }

    public function test_manual_material_rejects_warehouse_and_mixed_sources_without_writes(): void
    {
        $context = AdminApiTestContext::create();
        $siteRequest = $this->createMaterialRequest($context->organization, $context->user, [
            'material_id' => null,
            'material_name' => 'Монтажная пена',
            'material_quantity' => 12.5,
            'material_unit' => 'баллон',
        ]);
        $this->allowFulfillmentAccess();

        $warehouseResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/site-requests/{$siteRequest->id}/fulfillment-decision", [
                'source' => 'warehouse',
                'warehouse_id' => 1,
                'warehouse_quantity' => 12.5,
            ]);
        $mixedResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/site-requests/{$siteRequest->id}/fulfillment-decision", [
                'source' => 'mixed',
                'warehouse_id' => 1,
                'warehouse_quantity' => 5,
                'purchase_quantity' => 7.5,
            ]);

        $warehouseResponse->assertUnprocessable();
        $warehouseResponse->assertJsonPath('message', 'Для материала, введенного вручную, доступна только закупка.');
        $mixedResponse->assertUnprocessable();
        $mixedResponse->assertJsonPath('message', 'Для материала, введенного вручную, доступна только закупка.');
        $this->assertDatabaseCount('purchase_requests', 0);
        $this->assertDatabaseCount('purchase_request_lines', 0);
        $this->assertNull($siteRequest->fresh()->metadata['fulfillment_decision'] ?? null);
    }

    public function test_catalog_material_purchase_preserves_material_id(): void
    {
        $context = AdminApiTestContext::create();
        $material = Material::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Кладочный раствор',
            'is_active' => true,
        ]);
        $siteRequest = $this->createMaterialRequest($context->organization, $context->user, [
            'material_id' => $material->id,
            'material_name' => 'Кладочный раствор',
            'material_quantity' => 20,
            'material_unit' => 'мешок',
        ]);
        $this->allowFulfillmentAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/site-requests/{$siteRequest->id}/fulfillment-decision", [
                'source' => 'purchase',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.decision.source', 'purchase');
        $this->assertDatabaseHas('purchase_request_lines', [
            'purchase_request_id' => $response->json('data.decision.purchase_request_id'),
            'material_id' => $material->id,
            'name' => 'Кладочный раствор',
            'quantity' => 20,
            'unit' => 'мешок',
        ]);
    }

    public function test_fulfillment_does_not_expose_a_foreign_organization_request(): void
    {
        $ownerContext = AdminApiTestContext::create();
        $siteRequest = $this->createMaterialRequest($ownerContext->organization, $ownerContext->user, [
            'material_id' => null,
            'material_name' => 'Монтажная пена',
            'material_quantity' => 12.5,
            'material_unit' => 'баллон',
        ]);
        $foreignContext = AdminApiTestContext::create();
        $this->allowFulfillmentAccess();

        $response = $this->withHeaders($foreignContext->authHeaders())
            ->postJson("/api/v1/admin/site-requests/{$siteRequest->id}/fulfillment-decision", [
                'source' => 'purchase',
            ]);

        $response->assertNotFound();
        $this->assertDatabaseCount('purchase_requests', 0);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createMaterialRequest(Organization $organization, User $user, array $overrides): SiteRequest
    {
        $project = Project::factory()->create(['organization_id' => $organization->id]);

        return SiteRequest::query()->create(array_merge([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'title' => 'Материалы на объект',
            'status' => SiteRequestStatusEnum::APPROVED,
            'priority' => 'medium',
            'request_type' => SiteRequestTypeEnum::MATERIAL_REQUEST,
            'material_id' => null,
            'material_name' => 'Материал',
            'material_quantity' => 1,
            'material_unit' => 'шт',
        ], $overrides));
    }

    private function allowFulfillmentAccess(): void
    {
        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')->andReturn(true);
        });
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturn(true);
            $mock->shouldReceive('hasRole')->andReturn(true);
        });
    }
}
