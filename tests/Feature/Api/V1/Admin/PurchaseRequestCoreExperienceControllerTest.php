<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\Procurement\Enums\PurchaseRequestStatusEnum;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequestLine;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestStatusEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestTypeEnum;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Material;
use App\Models\MeasurementUnit;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class PurchaseRequestCoreExperienceControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_list_show_and_reject_purchase_request_without_organization_leaks(): void
    {
        Event::fake();

        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $siteRequest = $this->createSiteRequest($context, $project);
        $unit = $this->createUnit($context->organization->id, 'Piece', 'pcs');
        $material = $this->createMaterial($context->organization->id, $unit->id, 'Rebar A500', 'REB-PR');
        $assignee = User::factory()->create(['current_organization_id' => $context->organization->id]);
        $context->organization->users()->attach($assignee->id, [
            'is_owner' => false,
            'is_active' => true,
            'settings' => null,
        ]);
        $foreignPurchaseRequest = $this->createPurchaseRequest($foreignContext);
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $createResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/procurement/purchase-requests', [
                'site_request_id' => $siteRequest->id,
                'assigned_to' => $assignee->id,
                'needed_by' => now()->addDays(4)->toDateString(),
                'budget_amount' => 125000.50,
                'budget_currency' => 'RUB',
                'notes' => 'Urgent delivery to site',
                'lines' => [
                    [
                        'material_id' => $material->id,
                        'name' => 'Rebar A500',
                        'quantity' => 12.5,
                        'unit' => 't',
                        'specification' => '12 mm bars',
                    ],
                    [
                        'name' => 'Binding wire',
                        'quantity' => 50,
                        'unit' => 'kg',
                    ],
                ],
            ]);

        $createResponse->assertCreated();
        $createResponse->assertJsonPath('success', true);
        $createResponse->assertJsonPath('data.status', PurchaseRequestStatusEnum::PENDING->value);
        $createResponse->assertJsonPath('data.can_be_approved', true);
        $createResponse->assertJsonPath('data.site_request_id', $siteRequest->id);
        $createResponse->assertJsonPath('data.assigned_user.id', $assignee->id);
        $createResponse->assertJsonPath('data.lines.0.material_id', $material->id);
        $this->assertNotNull($createResponse->json('data.workflow_summary'));

        $purchaseRequest = PurchaseRequest::query()->findOrFail($createResponse->json('data.id'));
        $this->assertSame($context->organization->id, $purchaseRequest->organization_id);
        $this->assertSame($siteRequest->id, $purchaseRequest->site_request_id);
        $this->assertSame($assignee->id, $purchaseRequest->assigned_to);
        $this->assertSame('125000.50', (string) $purchaseRequest->budget_amount);
        $this->assertSame(2, $purchaseRequest->lines()->count());

        $indexResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/procurement/purchase-requests?per_page=20&status=pending');

        $indexResponse->assertOk();
        $ids = collect($indexResponse->json('data'))->pluck('id')->all();
        $this->assertContains($purchaseRequest->id, $ids);
        $this->assertNotContains($foreignPurchaseRequest->id, $ids);

        $showResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/procurement/purchase-requests/{$purchaseRequest->id}");

        $showResponse->assertOk();
        $showResponse->assertJsonPath('data.id', $purchaseRequest->id);
        $showResponse->assertJsonPath('data.site_request.project.id', $project->id);

        $foreignShowResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/procurement/purchase-requests/{$foreignPurchaseRequest->id}");

        $foreignShowResponse->assertNotFound();

        $rejectResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/procurement/purchase-requests/{$purchaseRequest->id}/reject", [
                'reason' => 'Budget moved to next delivery batch',
            ]);

        $rejectResponse->assertOk();
        $rejectResponse->assertJsonPath('data.status', PurchaseRequestStatusEnum::REJECTED->value);
        $this->assertStringContainsString(
            'Budget moved to next delivery batch',
            (string) $purchaseRequest->fresh()->notes
        );
    }

    public function test_purchase_request_creation_rejects_foreign_links_without_mutation(): void
    {
        Event::fake();

        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $ownUnit = $this->createUnit($context->organization->id, 'Piece', 'pcs');
        $foreignUnit = $this->createUnit($foreignContext->organization->id, 'Foreign piece', 'fpcs');
        $ownMaterial = $this->createMaterial($context->organization->id, $ownUnit->id, 'Own cement', 'CEM-OWN');
        $foreignMaterial = $this->createMaterial($foreignContext->organization->id, $foreignUnit->id, 'Foreign cement', 'CEM-FOR');
        $ownProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $foreignProject = Project::factory()->create(['organization_id' => $foreignContext->organization->id]);
        $foreignSiteRequest = $this->createSiteRequest($foreignContext, $foreignProject);
        $foreignAssignee = User::factory()->create(['current_organization_id' => $foreignContext->organization->id]);
        $foreignContext->organization->users()->attach($foreignAssignee->id, [
            'is_owner' => false,
            'is_active' => true,
            'settings' => null,
        ]);
        $ownSiteRequest = $this->createSiteRequest($context, $ownProject);
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $foreignSiteResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/procurement/purchase-requests', [
                'site_request_id' => $foreignSiteRequest->id,
                'lines' => [
                    [
                        'material_id' => $ownMaterial->id,
                        'name' => 'Own cement',
                        'quantity' => 10,
                        'unit' => 'bag',
                    ],
                ],
            ]);

        $foreignSiteResponse->assertStatus(422);

        $foreignAssigneeResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/procurement/purchase-requests', [
                'site_request_id' => $ownSiteRequest->id,
                'assigned_to' => $foreignAssignee->id,
                'lines' => [
                    [
                        'material_id' => $ownMaterial->id,
                        'name' => 'Own cement',
                        'quantity' => 10,
                        'unit' => 'bag',
                    ],
                ],
            ]);

        $foreignAssigneeResponse->assertStatus(422);

        $foreignMaterialResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/procurement/purchase-requests', [
                'site_request_id' => $ownSiteRequest->id,
                'lines' => [
                    [
                        'material_id' => $foreignMaterial->id,
                        'name' => 'Foreign cement',
                        'quantity' => 10,
                        'unit' => 'bag',
                    ],
                ],
            ]);

        $foreignMaterialResponse->assertStatus(422);

        $this->assertDatabaseMissing('purchase_requests', [
            'organization_id' => $context->organization->id,
        ]);
        $this->assertDatabaseMissing('purchase_request_lines', [
            'material_id' => $foreignMaterial->id,
        ]);
    }

    public function test_pending_purchase_request_can_be_approved_once_and_foreign_request_is_hidden(): void
    {
        Event::fake();

        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $purchaseRequest = $this->createPurchaseRequest($context, PurchaseRequestStatusEnum::PENDING);
        $foreignPurchaseRequest = $this->createPurchaseRequest($foreignContext, PurchaseRequestStatusEnum::PENDING);
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $approveResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/procurement/purchase-requests/{$purchaseRequest->id}/approve");

        $approveResponse->assertOk();
        $approveResponse->assertJsonPath('data.status', PurchaseRequestStatusEnum::APPROVED->value);
        $this->assertSame(PurchaseRequestStatusEnum::APPROVED, $purchaseRequest->fresh()->status);

        $secondApproveResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/procurement/purchase-requests/{$purchaseRequest->id}/approve");

        $secondApproveResponse->assertStatus(422);
        $this->assertSame(PurchaseRequestStatusEnum::APPROVED, $purchaseRequest->fresh()->status);

        $foreignApproveResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/procurement/purchase-requests/{$foreignPurchaseRequest->id}/approve");

        $foreignApproveResponse->assertNotFound();
        $this->assertSame(PurchaseRequestStatusEnum::PENDING, $foreignPurchaseRequest->fresh()->status);
    }

    public function test_direct_purchase_order_creation_from_request_is_blocked_until_proposal_selection(): void
    {
        Event::fake();

        $context = AdminApiTestContext::create();
        $purchaseRequest = $this->createPurchaseRequest($context, PurchaseRequestStatusEnum::APPROVED);
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/procurement/purchase-requests/{$purchaseRequest->id}/create-order", [
                'supplier_id' => 1,
            ]);

        $response->assertStatus(410);
        $this->assertSame(0, PurchaseOrder::query()
            ->where('purchase_request_id', $purchaseRequest->id)
            ->count());
    }

    private function createSiteRequest(AdminApiTestContext $context, Project $project): SiteRequest
    {
        return SiteRequest::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'user_id' => $context->user->id,
            'title' => 'Site material request',
            'request_type' => SiteRequestTypeEnum::MATERIAL_REQUEST->value,
            'status' => SiteRequestStatusEnum::APPROVED->value,
            'priority' => 'medium',
            'material_name' => 'Cement',
            'material_quantity' => 10,
            'material_unit' => 'bag',
        ]);
    }

    private function createPurchaseRequest(
        AdminApiTestContext $context,
        PurchaseRequestStatusEnum $status = PurchaseRequestStatusEnum::DRAFT
    ): PurchaseRequest {
        $purchaseRequest = PurchaseRequest::query()->create([
            'organization_id' => $context->organization->id,
            'request_number' => 'PR-' . $context->organization->id . '-' . uniqid(),
            'status' => $status->value,
            'budget_currency' => 'RUB',
        ]);

        PurchaseRequestLine::query()->create([
            'purchase_request_id' => $purchaseRequest->id,
            'name' => 'Existing line',
            'quantity' => 1,
            'unit' => 'pcs',
        ]);

        return $purchaseRequest;
    }

    private function createUnit(int $organizationId, string $name, string $shortName): MeasurementUnit
    {
        return MeasurementUnit::query()->create([
            'organization_id' => $organizationId,
            'name' => $name,
            'short_name' => $shortName,
            'type' => 'material',
            'is_default' => false,
            'is_system' => false,
        ]);
    }

    private function createMaterial(int $organizationId, int $unitId, string $name, string $code): Material
    {
        return Material::query()->create([
            'organization_id' => $organizationId,
            'name' => $name,
            'code' => $code,
            'measurement_unit_id' => $unitId,
            'category' => 'Procurement',
            'default_price' => 100,
            'is_active' => true,
        ]);
    }

    private function allowModuleAccess(): void
    {
        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')
                ->andReturnUsing(static fn (int $organizationId, string $moduleSlug): bool => in_array($moduleSlug, [
                    'procurement',
                    'basic-warehouse',
                ], true));
        });
    }

    private function allowAdminAccess(): void
    {
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturn(true);
            $mock->shouldReceive('hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['web_admin']);
            $mock->shouldReceive('getUserRoles')->andReturnUsing(
                static function (User $user, ?AuthorizationContext $context = null) {
                    return $user->roleAssignments()
                        ->where('is_active', true)
                        ->when($context !== null, static fn ($query) => $query->where('context_id', $context->id))
                        ->get();
                }
            );
        });
    }
}
