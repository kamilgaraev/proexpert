<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrderItem;
use App\BusinessModules\Features\Procurement\Models\PurchaseReceipt;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequestLine;
use App\BusinessModules\Features\Procurement\Models\SupplierProposal;
use App\BusinessModules\Features\Procurement\Models\SupplierProposalDecision;
use App\BusinessModules\Features\Procurement\Models\SupplierRequest;
use App\BusinessModules\Features\Procurement\Services\ProcurementLifecycleService;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\Models\Organization;
use Tests\TestCase;

class ProcurementLifecycleServiceTest extends TestCase
{
    public function test_approved_request_without_supplier_requests_expects_supplier_request_creation(): void
    {
        $purchaseRequest = $this->createPurchaseRequest('approved');

        $summary = app(ProcurementLifecycleService::class)->forPurchaseRequest($purchaseRequest->fresh());

        $this->assertSame('approved_without_supplier_requests', $summary->stage);
        $this->assertSame('create_supplier_request', $summary->nextAction);
        $this->assertTrue($summary->canCreateSupplierRequest);
        $this->assertFalse($summary->canAcceptProposal);
    }

    public function test_request_with_sent_supplier_request_waits_for_proposals(): void
    {
        $purchaseRequest = $this->createPurchaseRequest('approved');
        $this->createSupplierRequest($purchaseRequest, 'sent');

        $summary = app(ProcurementLifecycleService::class)->forPurchaseRequest($purchaseRequest->fresh());

        $this->assertSame('supplier_requests_sent', $summary->stage);
        $this->assertSame('wait_for_proposals', $summary->nextAction);
        $this->assertFalse($summary->canCreateSupplierRequest);
    }

    public function test_request_with_responded_supplier_request_waits_for_proposal_selection(): void
    {
        $purchaseRequest = $this->createPurchaseRequest('approved');
        $this->createSupplierRequest($purchaseRequest, 'responded');

        $summary = app(ProcurementLifecycleService::class)->forPurchaseRequest($purchaseRequest->fresh());

        $this->assertSame('proposals_received', $summary->stage);
        $this->assertSame('select_proposal', $summary->nextAction);
        $this->assertTrue($summary->canSelectProposal);
    }

    public function test_request_with_selected_proposal_can_accept_proposal(): void
    {
        $purchaseRequest = $this->createPurchaseRequest('approved');
        $supplierRequest = $this->createSupplierRequest($purchaseRequest, 'responded');
        $proposal = $this->createProposal($supplierRequest);
        $this->createDecision($supplierRequest, $proposal, 'selected');

        $summary = app(ProcurementLifecycleService::class)->forPurchaseRequest($purchaseRequest->fresh());

        $this->assertSame('proposal_selected', $summary->stage);
        $this->assertSame('accept_proposal', $summary->nextAction);
        $this->assertTrue($summary->canAcceptProposal);
    }

    public function test_expired_selected_proposal_cannot_be_accepted(): void
    {
        $purchaseRequest = $this->createPurchaseRequest('approved');
        $supplierRequest = $this->createSupplierRequest($purchaseRequest, 'responded');
        $proposal = $this->createProposal($supplierRequest, ['valid_until' => now()->subDay()->toDateString()]);
        $this->createDecision($supplierRequest, $proposal, 'selected');

        $summary = app(ProcurementLifecycleService::class)->forSupplierProposal($proposal->fresh());

        $this->assertSame('proposal_expired', $summary->stage);
        $this->assertFalse($summary->canAcceptProposal);
    }

    public function test_expired_supplier_request_returns_purchase_request_to_supplier_request_creation(): void
    {
        $purchaseRequest = $this->createPurchaseRequest('approved');
        $this->createSupplierRequest($purchaseRequest, 'sent', [
            'public_token_expires_at' => now()->subMinute(),
        ]);

        $summary = app(ProcurementLifecycleService::class)->forPurchaseRequest($purchaseRequest->fresh());

        $this->assertSame('approved_without_supplier_requests', $summary->stage);
        $this->assertSame('create_supplier_request', $summary->nextAction);
        $this->assertTrue($summary->canCreateSupplierRequest);
    }

    public function test_delivered_order_completes_purchase_request_lifecycle(): void
    {
        $purchaseRequest = $this->createPurchaseRequest('approved');
        $order = $this->createPurchaseOrder($purchaseRequest, 'delivered');

        $summary = app(ProcurementLifecycleService::class)->forPurchaseRequest($purchaseRequest->fresh());

        $this->assertSame($order->id, $purchaseRequest->purchaseOrders()->first()?->id);
        $this->assertSame('completed', $summary->stage);
        $this->assertNull($summary->nextAction);
    }

    public function test_receipt_status_resolution_returns_partial_and_delivered_states(): void
    {
        $purchaseRequest = $this->createPurchaseRequest('approved');
        $order = $this->createPurchaseOrder($purchaseRequest, 'confirmed');
        $warehouse = OrganizationWarehouse::query()->create([
            'organization_id' => $order->organization_id,
            'name' => 'Основной склад',
            'code' => 'WH-LC-' . $order->id,
            'warehouse_type' => OrganizationWarehouse::TYPE_CENTRAL,
            'is_main' => true,
            'is_active' => true,
        ]);
        $item = PurchaseOrderItem::query()->create([
            'purchase_order_id' => $order->id,
            'material_name' => 'Материал',
            'quantity' => 10,
            'unit' => 'шт',
            'unit_price' => 100,
            'total_price' => 1000,
        ]);
        $otherItem = PurchaseOrderItem::query()->create([
            'purchase_order_id' => $order->id,
            'material_name' => 'Материал 2',
            'quantity' => 5,
            'unit' => 'шт',
            'unit_price' => 100,
            'total_price' => 500,
        ]);

        $receipt = PurchaseReceipt::query()->create([
            'organization_id' => $order->organization_id,
            'purchase_order_id' => $order->id,
            'warehouse_id' => $warehouse->id,
            'receipt_number' => 'REC-LC-001',
            'receipt_date' => now()->toDateString(),
            'status' => 'posted',
        ]);

        $receipt->lines()->create([
            'purchase_order_item_id' => $otherItem->id,
            'quantity_received' => 5,
            'price' => 100,
            'total_amount' => 500,
        ]);

        $service = app(ProcurementLifecycleService::class);
        $this->assertSame('partially_delivered', $service->resolveOrderReceiptStatus($order->fresh())->value);

        $receipt->lines()->create([
            'purchase_order_item_id' => $item->id,
            'quantity_received' => 10,
            'price' => 100,
            'total_amount' => 1000,
        ]);

        $this->assertSame('delivered', $service->resolveOrderReceiptStatus($order->fresh())->value);
    }

    private function createPurchaseRequest(string $status): PurchaseRequest
    {
        $organization = Organization::factory()->create();
        $purchaseRequest = PurchaseRequest::query()->create([
            'organization_id' => $organization->id,
            'request_number' => 'PR-LC-' . str_pad((string) $organization->id, 4, '0', STR_PAD_LEFT),
            'status' => $status,
        ]);

        PurchaseRequestLine::query()->create([
            'purchase_request_id' => $purchaseRequest->id,
            'name' => 'Материал',
            'quantity' => 1,
            'unit' => 'шт',
        ]);

        return $purchaseRequest;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createSupplierRequest(PurchaseRequest $purchaseRequest, string $status, array $overrides = []): SupplierRequest
    {
        return SupplierRequest::query()->create(array_merge([
            'organization_id' => $purchaseRequest->organization_id,
            'purchase_request_id' => $purchaseRequest->id,
            'request_number' => 'SR-LC-' . $purchaseRequest->id,
            'status' => $status,
            'public_token' => 'token-' . $purchaseRequest->id . str_repeat('a', 40),
            'public_token_expires_at' => now()->addDay(),
        ], $overrides));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createProposal(SupplierRequest $supplierRequest, array $overrides = []): SupplierProposal
    {
        return SupplierProposal::query()->create(array_merge([
            'organization_id' => $supplierRequest->organization_id,
            'supplier_request_id' => $supplierRequest->id,
            'proposal_number' => 'KP-LC-' . $supplierRequest->id,
            'proposal_date' => now()->toDateString(),
            'status' => 'submitted',
            'subtotal_amount' => 100,
            'delivery_amount' => 0,
            'vat_amount' => 0,
            'total_amount' => 100,
            'currency' => 'RUB',
        ], $overrides));
    }

    private function createDecision(
        SupplierRequest $supplierRequest,
        SupplierProposal $proposal,
        string $status
    ): SupplierProposalDecision {
        return SupplierProposalDecision::query()->create([
            'organization_id' => $supplierRequest->organization_id,
            'supplier_request_id' => $supplierRequest->id,
            'winning_supplier_proposal_id' => $proposal->id,
            'cheapest_supplier_proposal_id' => $proposal->id,
            'status' => $status,
            'is_lowest_price_selected' => true,
            'comparison_snapshot' => [],
        ]);
    }

    private function createPurchaseOrder(PurchaseRequest $purchaseRequest, string $status): PurchaseOrder
    {
        return PurchaseOrder::query()->create([
            'organization_id' => $purchaseRequest->organization_id,
            'purchase_request_id' => $purchaseRequest->id,
            'order_number' => 'PO-LC-' . $purchaseRequest->id,
            'order_date' => now()->toDateString(),
            'status' => $status,
            'total_amount' => 100,
            'currency' => 'RUB',
        ]);
    }
}
