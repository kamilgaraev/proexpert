<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance;
use App\BusinessModules\Features\Procurement\Enums\PurchaseOrderStatusEnum;
use App\BusinessModules\Features\Procurement\Enums\PurchaseRequestStatusEnum;
use App\BusinessModules\Features\Procurement\Enums\SupplierProposalStatusEnum;
use App\BusinessModules\Features\Procurement\Enums\SupplierRequestStatusEnum;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequestLine;
use App\BusinessModules\Features\Procurement\Models\SupplierProposal;
use App\BusinessModules\Features\Procurement\Models\SupplierRequest;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Material;
use App\Models\MeasurementUnit;
use App\Models\Supplier;
use App\Models\User;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class ProcurementSupplierFlowCoreExperienceControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_run_supplier_flow_to_purchase_order_receipt_without_organization_leaks(): void
    {
        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $unit = $this->createUnit($context->organization->id);
        $material = $this->createMaterial($context->organization->id, $unit->id);
        $purchaseRequest = $this->createPurchaseRequest($context->organization->id, $material->id);
        $firstSupplier = $this->createSupplier($context->organization->id, 'First Supplier', 'first@example.test');
        $secondSupplier = $this->createSupplier($context->organization->id, 'Second Supplier', 'second@example.test');
        $warehouse = $this->createWarehouse($context->organization->id);
        $foreignOrder = $this->createForeignPurchaseOrder($foreignContext);
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $bulkResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/procurement/supplier-requests/bulk', [
                'purchase_request_id' => $purchaseRequest->id,
                'send_immediately' => true,
                'comment' => 'Collect commercial offers',
                'suppliers' => [
                    ['supplier_id' => $firstSupplier->id],
                    ['supplier_id' => $secondSupplier->id],
                ],
            ]);

        $bulkResponse->assertCreated();
        $bulkResponse->assertJsonPath('success', true);
        $bulkResponse->assertJsonPath('data.0.status', SupplierRequestStatusEnum::SENT->value);
        $bulkResponse->assertJsonPath('data.1.status', SupplierRequestStatusEnum::SENT->value);
        $bulkResponse->assertJsonPath('data.0.lines_count', 1);
        $this->assertNotNull($bulkResponse->json('data.0.public_url'));
        $this->assertSame(2, SupplierRequest::query()->where('purchase_request_id', $purchaseRequest->id)->count());

        $supplierRequestIndexResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/procurement/supplier-requests?purchase_request_id={$purchaseRequest->id}");

        $supplierRequestIndexResponse->assertOk();
        $supplierRequestIndexResponse->assertJsonPath('data.0.lines_count', 1);

        $firstSupplierRequest = SupplierRequest::query()
            ->where('supplier_id', $firstSupplier->id)
            ->firstOrFail();
        $secondSupplierRequest = SupplierRequest::query()
            ->where('supplier_id', $secondSupplier->id)
            ->firstOrFail();

        $firstProposal = $this->createProposalThroughApi($context, $firstSupplierRequest, 1200.0);
        $secondProposal = $this->createProposalThroughApi($context, $secondSupplierRequest, 950.0);

        $proposalIndexResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/procurement/proposals?per_page=20&status=submitted');

        $proposalIndexResponse->assertOk();
        $proposalIds = collect($proposalIndexResponse->json('data'))->pluck('id')->all();
        $this->assertContains($firstProposal->id, $proposalIds);
        $this->assertContains($secondProposal->id, $proposalIds);

        $comparisonResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/procurement/purchase-requests/{$purchaseRequest->id}/proposal-comparison");

        $comparisonResponse->assertOk();
        $comparisonResponse->assertJsonPath('data.cheapest_supplier_proposal_id', $secondProposal->id);

        $expensiveDecisionResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/procurement/purchase-requests/{$purchaseRequest->id}/proposal-decision", [
                'supplier_proposal_id' => $firstProposal->id,
            ]);

        $expensiveDecisionResponse->assertStatus(422);

        $decisionResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/procurement/purchase-requests/{$purchaseRequest->id}/proposal-decision", [
                'supplier_proposal_id' => $secondProposal->id,
            ]);

        $decisionResponse->assertOk();
        $decisionResponse->assertJsonPath('data.winning_supplier_proposal_id', $secondProposal->id);
        $decisionResponse->assertJsonPath('data.is_lowest_price_selected', true);

        $acceptResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/procurement/proposals/{$secondProposal->id}/accept");

        $acceptResponse->assertOk();
        $acceptResponse->assertJsonPath('data.status', SupplierProposalStatusEnum::ACCEPTED->value);
        $this->assertSame(SupplierProposalStatusEnum::ACCEPTED, $secondProposal->fresh()->status);

        $purchaseOrder = PurchaseOrder::query()
            ->where('accepted_supplier_proposal_id', $secondProposal->id)
            ->with('items')
            ->firstOrFail();
        $this->assertSame(PurchaseOrderStatusEnum::CONFIRMED, $purchaseOrder->status);
        $this->assertSame($purchaseRequest->id, $purchaseOrder->purchase_request_id);
        $this->assertSame($secondSupplier->id, $purchaseOrder->supplier_id);
        $this->assertSame(1, $purchaseOrder->items()->count());

        $orderIndexResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/procurement/purchase-orders?per_page=20&status=confirmed');

        $orderIndexResponse->assertOk();
        $orderIds = collect($orderIndexResponse->json('data'))->pluck('id')->all();
        $this->assertContains($purchaseOrder->id, $orderIds);
        $this->assertNotContains($foreignOrder->id, $orderIds);

        $foreignOrderResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/procurement/purchase-orders/{$foreignOrder->id}");

        $foreignOrderResponse->assertNotFound();

        $deliveryResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/procurement/purchase-orders/{$purchaseOrder->id}/mark-in-delivery");

        $deliveryResponse->assertOk();
        $deliveryResponse->assertJsonPath('data.status', PurchaseOrderStatusEnum::IN_DELIVERY->value);

        $item = $purchaseOrder->items()->firstOrFail();
        $receiveResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/procurement/purchase-orders/{$purchaseOrder->id}/receive-materials", [
                'warehouse_id' => $warehouse->id,
                'receipt_date' => now()->toDateString(),
                'items' => [
                    [
                        'item_id' => $item->id,
                        'quantity_received' => 5,
                        'price' => 190,
                    ],
                ],
            ]);

        $receiveResponse->assertOk();
        $receiveResponse->assertJsonPath('data.status', PurchaseOrderStatusEnum::DELIVERED->value);
        $receiveResponse->assertJsonPath('data.receipts.0.lines.0.purchase_order_item_id', $item->id);

        $this->assertDatabaseHas('purchase_receipts', [
            'organization_id' => $context->organization->id,
            'purchase_order_id' => $purchaseOrder->id,
            'warehouse_id' => $warehouse->id,
        ]);
        $this->assertDatabaseHas('warehouse_balances', [
            'organization_id' => $context->organization->id,
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
        ]);
        $balance = WarehouseBalance::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('material_id', $material->id)
            ->firstOrFail();
        $this->assertSame('5.000', (string) $balance->available_quantity);

        $duplicateAcceptResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/procurement/proposals/{$secondProposal->id}/accept");

        $duplicateAcceptResponse->assertStatus(422);
        $this->assertSame(1, PurchaseOrder::query()
            ->where('accepted_supplier_proposal_id', $secondProposal->id)
            ->count());
    }

    public function test_supplier_flow_rejects_foreign_purchase_request_supplier_and_proposal_links_without_mutation(): void
    {
        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $unit = $this->createUnit($context->organization->id);
        $foreignUnit = $this->createUnit($foreignContext->organization->id);
        $material = $this->createMaterial($context->organization->id, $unit->id);
        $foreignMaterial = $this->createMaterial($foreignContext->organization->id, $foreignUnit->id);
        $purchaseRequest = $this->createPurchaseRequest($context->organization->id, $material->id);
        $foreignPurchaseRequest = $this->createPurchaseRequest($foreignContext->organization->id, $foreignMaterial->id);
        $supplier = $this->createSupplier($context->organization->id, 'Own Supplier', 'own@example.test');
        $foreignSupplier = $this->createSupplier($foreignContext->organization->id, 'Foreign Supplier', 'foreign@example.test');
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $foreignPurchaseRequestResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/procurement/supplier-requests', [
                'purchase_request_id' => $foreignPurchaseRequest->id,
                'supplier_id' => $supplier->id,
            ]);

        $foreignPurchaseRequestResponse->assertStatus(422);

        $foreignSupplierResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/procurement/supplier-requests', [
                'purchase_request_id' => $purchaseRequest->id,
                'supplier_id' => $foreignSupplier->id,
            ]);

        $foreignSupplierResponse->assertStatus(422);
        $this->assertSame(0, SupplierRequest::query()
            ->where('organization_id', $context->organization->id)
            ->count());

        $supplierRequest = SupplierRequest::query()->create([
            'organization_id' => $context->organization->id,
            'purchase_request_id' => $purchaseRequest->id,
            'supplier_id' => $supplier->id,
            'request_number' => 'SR-OWN-001',
            'status' => SupplierRequestStatusEnum::SENT,
            'public_token' => 'own-token',
            'public_token_expires_at' => now()->addDay(),
        ]);

        $foreignSupplierRequest = SupplierRequest::query()->create([
            'organization_id' => $foreignContext->organization->id,
            'purchase_request_id' => $foreignPurchaseRequest->id,
            'supplier_id' => $foreignSupplier->id,
            'request_number' => 'SR-FOR-001',
            'status' => SupplierRequestStatusEnum::SENT,
            'public_token' => 'foreign-token',
            'public_token_expires_at' => now()->addDay(),
        ]);

        $foreignProposalResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/procurement/proposals', $this->proposalPayload($foreignSupplierRequest, 1000.0));

        $foreignProposalResponse->assertStatus(422);
        $this->assertSame(0, SupplierProposal::query()
            ->where('organization_id', $context->organization->id)
            ->count());

        $ownProposalResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/procurement/proposals', $this->proposalPayload($supplierRequest, 1000.0));

        $ownProposalResponse->assertCreated();
        $ownProposal = SupplierProposal::query()->findOrFail($ownProposalResponse->json('data.id'));

        $foreignProposal = SupplierProposal::query()->create([
            'organization_id' => $foreignContext->organization->id,
            'supplier_request_id' => $foreignSupplierRequest->id,
            'supplier_id' => $foreignSupplier->id,
            'proposal_number' => 'KP-FOR-001',
            'proposal_date' => now()->toDateString(),
            'status' => SupplierProposalStatusEnum::SUBMITTED,
            'subtotal_amount' => 100,
            'delivery_amount' => 0,
            'vat_amount' => 0,
            'total_amount' => 100,
            'currency' => 'RUB',
            'vat_mode' => 'included',
            'valid_until' => now()->addDay()->toDateString(),
        ]);

        $foreignDecisionResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/procurement/purchase-requests/{$purchaseRequest->id}/proposal-decision", [
                'supplier_proposal_id' => $foreignProposal->id,
            ]);

        $foreignDecisionResponse->assertStatus(422);
        $this->assertDatabaseMissing('supplier_proposal_decisions', [
            'organization_id' => $context->organization->id,
            'winning_supplier_proposal_id' => $foreignProposal->id,
        ]);
    }

    private function createProposalThroughApi(
        AdminApiTestContext $context,
        SupplierRequest $supplierRequest,
        float $totalAmount
    ): SupplierProposal {
        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/procurement/proposals', $this->proposalPayload($supplierRequest, $totalAmount));

        $response->assertCreated();
        $response->assertJsonPath('data.supplier_request_id', $supplierRequest->id);
        $this->assertEquals($totalAmount, $response->json('data.total_amount'));

        return SupplierProposal::query()->findOrFail($response->json('data.id'));
    }

    private function proposalPayload(SupplierRequest $supplierRequest, float $totalAmount): array
    {
        $line = $supplierRequest->lines()->first();

        return [
            'supplier_request_id' => $supplierRequest->id,
            'proposal_date' => now()->toDateString(),
            'subtotal_amount' => $totalAmount,
            'delivery_amount' => 0,
            'vat_amount' => 0,
            'total_amount' => $totalAmount,
            'currency' => 'RUB',
            'vat_mode' => 'included',
            'valid_until' => now()->addDays(10)->toDateString(),
            'delivery_due_date' => now()->addDays(5)->toDateString(),
            'payment_terms' => 'Payment after delivery',
            'delivery_terms' => 'Delivery to warehouse',
            'items' => [
                [
                    'supplier_request_line_id' => $line?->id,
                    'name' => $line?->name ?? 'Material line',
                    'quantity' => 5,
                    'unit' => $line?->unit ?? 'pcs',
                    'unit_price' => round($totalAmount / 5, 2),
                    'total_amount' => $totalAmount,
                ],
            ],
        ];
    }

    private function createPurchaseRequest(int $organizationId, int $materialId): PurchaseRequest
    {
        $purchaseRequest = PurchaseRequest::query()->create([
            'organization_id' => $organizationId,
            'request_number' => 'PR-FLOW-' . $organizationId . '-' . uniqid(),
            'status' => PurchaseRequestStatusEnum::APPROVED,
            'budget_currency' => 'RUB',
        ]);

        PurchaseRequestLine::query()->create([
            'purchase_request_id' => $purchaseRequest->id,
            'material_id' => $materialId,
            'name' => 'Rebar A500',
            'quantity' => 5,
            'unit' => 'pcs',
        ]);

        return $purchaseRequest;
    }

    private function createForeignPurchaseOrder(AdminApiTestContext $context): PurchaseOrder
    {
        $unit = $this->createUnit($context->organization->id);
        $material = $this->createMaterial($context->organization->id, $unit->id);
        $purchaseRequest = $this->createPurchaseRequest($context->organization->id, $material->id);
        $supplier = $this->createSupplier($context->organization->id, 'Foreign Order Supplier', 'foreign-order@example.test');

        return PurchaseOrder::query()->create([
            'organization_id' => $context->organization->id,
            'purchase_request_id' => $purchaseRequest->id,
            'supplier_id' => $supplier->id,
            'order_number' => 'PO-FOR-' . uniqid(),
            'order_date' => now()->toDateString(),
            'status' => PurchaseOrderStatusEnum::CONFIRMED,
            'total_amount' => 10,
            'currency' => 'RUB',
        ]);
    }

    private function createSupplier(int $organizationId, string $name, string $email): Supplier
    {
        return Supplier::query()->create([
            'organization_id' => $organizationId,
            'name' => $name,
            'email' => $email,
            'is_active' => true,
        ]);
    }

    private function createUnit(int $organizationId): MeasurementUnit
    {
        return MeasurementUnit::query()->create([
            'organization_id' => $organizationId,
            'name' => 'Piece',
            'short_name' => 'pcs',
            'type' => 'material',
            'is_default' => false,
            'is_system' => false,
        ]);
    }

    private function createMaterial(int $organizationId, int $unitId): Material
    {
        return Material::query()->create([
            'organization_id' => $organizationId,
            'name' => 'Rebar A500',
            'code' => 'REB-FLOW-' . $organizationId . '-' . uniqid(),
            'measurement_unit_id' => $unitId,
            'category' => 'Procurement',
            'default_price' => 100,
            'is_active' => true,
        ]);
    }

    private function createWarehouse(int $organizationId): OrganizationWarehouse
    {
        return OrganizationWarehouse::query()->create([
            'organization_id' => $organizationId,
            'name' => 'Main warehouse',
            'code' => 'WH-FLOW-' . $organizationId,
            'warehouse_type' => OrganizationWarehouse::TYPE_CENTRAL,
            'is_main' => true,
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
