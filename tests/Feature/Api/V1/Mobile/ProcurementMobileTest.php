<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance;
use App\BusinessModules\Features\Procurement\Enums\ProcurementAuditEventTypeEnum;
use App\BusinessModules\Features\Procurement\Models\ProcurementApproval;
use App\BusinessModules\Features\Procurement\Models\ProcurementAuditEvent;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrderItem;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\Procurement\Models\SupplierProposal;
use App\BusinessModules\Features\Procurement\Models\SupplierProposalDecision;
use App\BusinessModules\Features\Procurement\Models\SupplierRequest;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestPriorityEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestStatusEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestTypeEnum;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Material;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class ProcurementMobileTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_summary_lists_requests_orders_and_assigned_approvals(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $purchaseRequest = $this->purchaseRequest($context, $project, ['status' => 'pending']);
        $order = $this->purchaseOrder($purchaseRequest, ['status' => 'confirmed']);
        $this->orderItem($context, $order, ['quantity' => 4]);
        $approval = $this->approval($context, 'budget_exceeded');
        $this->allowAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/mobile/procurement/summary?project_id=' . $project->id);

        $response->assertOk()
            ->assertJsonPath('data.summary.purchase_requests_count', 1)
            ->assertJsonPath('data.summary.purchase_orders_count', 1)
            ->assertJsonPath('data.summary.receivable_orders_count', 1)
            ->assertJsonPath('data.summary.pending_approvals_count', 1)
            ->assertJsonPath('data.purchase_requests.0.id', $purchaseRequest->id)
            ->assertJsonPath('data.purchase_orders.0.id', $order->id)
            ->assertJsonPath('data.assigned_approvals.0.id', $approval->id);

        $this->assertContains('receive_materials', $response->json('data.purchase_orders.0.available_actions'));
        $this->assertContains('comment', $response->json('data.purchase_orders.0.available_actions'));
        $this->assertContains('approve', $response->json('data.assigned_approvals.0.available_actions'));

        $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/mobile/procurement/purchase-orders/' . $order->id)
            ->assertOk()
            ->assertJsonPath('data.order.id', $order->id)
            ->assertJsonPath('data.order.items.0.remaining_quantity', 4);
    }

    public function test_mobile_approval_action_updates_procurement_decision(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $approval = $this->approval($context, 'non_lowest_price');
        $this->allowAccess();

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/mobile/procurement/approvals/' . $approval->id . '/approve', [
                'comment' => 'Согласовано для срочной поставки.',
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $approval->id)
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.comment', 'Согласовано для срочной поставки.');

        $this->assertDatabaseHas('procurement_approvals', [
            'id' => $approval->id,
            'status' => 'approved',
            'comment' => 'Согласовано для срочной поставки.',
        ]);
        $this->assertSame('approved', (string) $approval->approvable()->firstOrFail()->status->value);
    }

    public function test_mobile_receive_materials_bridges_purchase_order_to_warehouse_and_comments(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $purchaseRequest = $this->purchaseRequest($context, $project, ['status' => 'approved']);
        $order = $this->purchaseOrder($purchaseRequest, ['status' => 'confirmed']);
        $item = $this->orderItem($context, $order, ['quantity' => 5, 'unit_price' => 80]);
        $warehouse = $this->warehouse($context);
        $this->allowAccess();

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/mobile/procurement/purchase-orders/' . $order->id . '/comments', [
                'comment' => 'Поставка ожидается до обеда.',
            ])
            ->assertOk()
            ->assertJsonPath('data.comments.0.comment', 'Поставка ожидается до обеда.');

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/mobile/procurement/purchase-orders/' . $order->id . '/receive-materials', [
                'warehouse_id' => $warehouse->id,
                'items' => [
                    [
                        'item_id' => $item->id,
                        'quantity_received' => 1,
                        'price' => 80,
                    ],
                ],
            ])
            ->assertStatus(422);

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/mobile/procurement/purchase-orders/' . $order->id . '/receive-materials', [
                'warehouse_id' => $warehouse->id,
                'items' => [
                    [
                        'item_id' => $item->id,
                        'quantity_received' => 3,
                        'price' => 80,
                    ],
                ],
                'receipt_date' => '2026-05-22',
                'notes' => 'Принята первая часть поставки.',
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonPath('data.status', 'partially_delivered')
            ->assertJsonPath('data.items.0.received_quantity', 3)
            ->assertJsonPath('data.items.0.remaining_quantity', 2);

        $this->assertDatabaseHas('procurement_audit_events', [
            'subject_id' => $order->id,
            'event_type' => ProcurementAuditEventTypeEnum::PURCHASE_ORDER_COMMENTED->value,
        ]);
        $this->assertDatabaseHas('purchase_receipts', [
            'purchase_order_id' => $order->id,
            'warehouse_id' => $warehouse->id,
            'receipt_date' => '2026-05-22 00:00:00',
            'notes' => 'Принята первая часть поставки.',
        ]);
        $this->assertSame(3.0, (float) WarehouseBalance::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('material_id', $item->material_id)
            ->value('available_quantity'));
    }

    public function test_mobile_receive_materials_requires_receive_permission(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'worker');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $purchaseRequest = $this->purchaseRequest($context, $project, ['status' => 'approved']);
        $order = $this->purchaseOrder($purchaseRequest, ['status' => 'confirmed']);
        $item = $this->orderItem($context, $order);
        $warehouse = $this->warehouse($context);
        $this->allowAccess([
            'procurement.view',
            'procurement.purchase_orders.view',
        ]);

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/mobile/procurement/purchase-orders/' . $order->id . '/receive-materials', [
                'warehouse_id' => $warehouse->id,
                'items' => [
                    [
                        'item_id' => $item->id,
                        'quantity_received' => 1,
                        'price' => 80,
                    ],
                ],
            ])
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'PERMISSION_DENIED');

        $this->assertDatabaseMissing('purchase_receipts', [
            'purchase_order_id' => $order->id,
            'warehouse_id' => $warehouse->id,
        ]);
    }

    private function purchaseRequest(AdminApiTestContext $context, Project $project, array $attributes = []): PurchaseRequest
    {
        $siteRequest = SiteRequest::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'user_id' => $context->user->id,
            'title' => 'Поставка бетона',
            'request_type' => SiteRequestTypeEnum::MATERIAL_REQUEST->value,
            'status' => SiteRequestStatusEnum::APPROVED->value,
            'priority' => SiteRequestPriorityEnum::MEDIUM->value,
            'material_name' => 'Бетон М300',
            'material_quantity' => 5,
            'material_unit' => 'м3',
        ]);

        return PurchaseRequest::query()->create(array_merge([
            'organization_id' => $context->organization->id,
            'site_request_id' => $siteRequest->id,
            'assigned_to' => $context->user->id,
            'request_number' => 'PR-MOB-' . $siteRequest->id,
            'status' => 'approved',
            'needed_by' => now()->addDays(2)->toDateString(),
            'budget_amount' => 4000,
            'budget_currency' => 'RUB',
        ], $attributes));
    }

    private function purchaseOrder(PurchaseRequest $purchaseRequest, array $attributes = []): PurchaseOrder
    {
        return PurchaseOrder::query()->create(array_merge([
            'organization_id' => $purchaseRequest->organization_id,
            'purchase_request_id' => $purchaseRequest->id,
            'order_number' => 'PO-MOB-' . $purchaseRequest->id,
            'order_date' => now()->toDateString(),
            'status' => 'confirmed',
            'supplier_snapshot' => ['display_name' => 'Поставщик материалов'],
            'total_amount' => 400,
            'currency' => 'RUB',
        ], $attributes));
    }

    private function orderItem(AdminApiTestContext $context, PurchaseOrder $order, array $attributes = []): PurchaseOrderItem
    {
        $material = Material::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Бетон М300',
            'code' => 'CONCRETE-' . $order->id,
            'default_price' => 80,
            'is_active' => true,
        ]);

        return PurchaseOrderItem::query()->create(array_merge([
            'purchase_order_id' => $order->id,
            'material_id' => $material->id,
            'material_name' => $material->name,
            'quantity' => 5,
            'unit' => 'м3',
            'unit_price' => 80,
            'total_price' => 400,
        ], $attributes));
    }

    private function warehouse(AdminApiTestContext $context): OrganizationWarehouse
    {
        return OrganizationWarehouse::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Основной склад',
            'code' => 'WH-MOB-' . $context->organization->id,
            'warehouse_type' => OrganizationWarehouse::TYPE_CENTRAL,
            'is_main' => true,
            'is_active' => true,
        ]);
    }

    private function approval(AdminApiTestContext $context, string $reasonCode): ProcurementApproval
    {
        $purchaseRequest = PurchaseRequest::query()->create([
            'organization_id' => $context->organization->id,
            'request_number' => 'PR-APR-MOB-' . $reasonCode,
            'status' => 'approved',
            'budget_amount' => 1000,
            'budget_currency' => 'RUB',
        ]);
        $supplierRequest = SupplierRequest::query()->create([
            'organization_id' => $context->organization->id,
            'purchase_request_id' => $purchaseRequest->id,
            'request_number' => 'SR-APR-MOB-' . $reasonCode,
            'status' => 'responded',
        ]);
        $proposal = SupplierProposal::query()->create([
            'organization_id' => $context->organization->id,
            'supplier_request_id' => $supplierRequest->id,
            'proposal_number' => 'KP-MOB-' . $reasonCode,
            'proposal_date' => now()->toDateString(),
            'status' => 'submitted',
            'supplier_snapshot' => ['display_name' => 'Поставщик материалов'],
            'subtotal_amount' => 1200,
            'delivery_amount' => 0,
            'vat_amount' => 0,
            'total_amount' => 1200,
            'currency' => 'RUB',
        ]);
        $decision = SupplierProposalDecision::query()->create([
            'organization_id' => $context->organization->id,
            'supplier_request_id' => $supplierRequest->id,
            'winning_supplier_proposal_id' => $proposal->id,
            'cheapest_supplier_proposal_id' => $proposal->id,
            'status' => 'approval_required',
            'is_lowest_price_selected' => true,
            'comparison_snapshot' => [],
            'selected_by' => null,
            'selected_at' => now(),
        ]);

        return ProcurementApproval::query()->create([
            'organization_id' => $context->organization->id,
            'approvable_type' => $decision->getMorphClass(),
            'approvable_id' => $decision->id,
            'reason_code' => $reasonCode,
            'status' => 'pending',
            'requested_by' => null,
            'requested_at' => now(),
            'context' => [
                'selected_total' => 1200,
                'budget_amount' => 1000,
                'currency' => 'RUB',
            ],
        ]);
    }

    private function allowAccess(?array $allowedPermissions = null): void
    {
        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')->andReturn(true);
        });

        $this->mock(AuthorizationService::class, function (MockInterface $mock) use ($allowedPermissions): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturnUsing(
                static fn (User $user, string $permission): bool => $allowedPermissions === null
                    || in_array($permission, $allowedPermissions, true)
            );
            $mock->shouldReceive('hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['foreman']);
            $mock->shouldReceive('getUserRoles')->andReturnUsing(
                static function (User $user, ?AuthorizationContext $context = null) {
                    return $user->roleAssignments()
                        ->where('is_active', true)
                        ->when($context !== null, static fn ($query) => $query->where('context_id', $context->id))
                        ->get();
                }
            );
            $mock->shouldReceive('getUserPermissionsStructured')->andReturn([
                'modules' => [
                    'procurement' => $allowedPermissions ?? [
                        'procurement.view',
                        'procurement.purchase_requests.view',
                        'procurement.purchase_orders.view',
                        'procurement.purchase_orders.receive',
                        'procurement.purchase_orders.comment',
                        'procurement.approvals.view',
                        'procurement.approvals.resolve',
                    ],
                ],
            ]);
        });
    }
}
