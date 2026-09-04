<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\InvoiceType;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance;
use App\BusinessModules\Features\Procurement\Enums\PurchaseOrderStatusEnum;
use App\BusinessModules\Features\Procurement\Enums\PurchaseReceiptDocumentStatusEnum;
use App\BusinessModules\Features\Procurement\Enums\PurchaseRequestStatusEnum;
use App\BusinessModules\Features\Procurement\Enums\SupplierProposalStatusEnum;
use App\BusinessModules\Features\Procurement\Enums\SupplierRequestStatusEnum;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrderItem;
use App\BusinessModules\Features\Procurement\Models\PurchaseReceipt;
use App\BusinessModules\Features\Procurement\Models\PurchaseReceiptDocument;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequestLine;
use App\BusinessModules\Features\Procurement\Models\SupplierProposal;
use App\BusinessModules\Features\Procurement\Models\SupplierRequest;
use App\BusinessModules\Features\Procurement\Services\SupplierPartyService;
use App\BusinessModules\Features\Procurement\Services\SupplierRequestVersionService;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestStatusEnum;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Contractor;
use App\Models\Material;
use App\Models\MeasurementUnit;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\User;
use App\Modules\Core\AccessController;
use App\Services\Storage\DTO\CurrentStoredFile;
use App\Services\Storage\FileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\Support\EnablesImmutableAuditWriter;
use Tests\TestCase;

class ProcurementSupplierFlowCoreExperienceControllerTest extends TestCase
{
    use EnablesImmutableAuditWriter;
    use RefreshDatabase;

    private const INCOMING_UPD_FILE_ID = 'ON_NSCHFDOPPR_2BM-7712345678-771201001-20260904-1_2BM-1654321098-165401001-20260904-1_20260904_1';

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

        $this->assertSame(SupplierProposalStatusEnum::ACCEPTED, $secondProposal->fresh()->status);

        $purchaseOrder = PurchaseOrder::query()
            ->where('accepted_supplier_proposal_id', $secondProposal->id)
            ->with('items')
            ->firstOrFail();
        $this->assertSame(PurchaseOrderStatusEnum::CONFIRMED, $purchaseOrder->status);
        $this->assertSame($purchaseRequest->id, $purchaseOrder->purchase_request_id);
        $this->assertSame($secondSupplier->id, $purchaseOrder->supplier_id);
        $this->assertSame(1, $purchaseOrder->items()->count());
        $paymentDocument = $this->createPaidProcurementPaymentDocument($context, $purchaseOrder, 950);

        $paymentDocumentsResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/payments/documents?purchase_order_id={$purchaseOrder->id}");

        $paymentDocumentsResponse->assertOk();
        $paymentDocumentsResponse->assertJsonPath('data.0.id', $paymentDocument->id);

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
                'idempotency_key' => '052bda50-fb35-4b67-8990-b74e3ac83929',
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
        $receiveResponse->assertJsonPath('data.receipts.0.metadata.receipt_document.form_code', 'ТОРГ-12');
        $receiveResponse->assertJsonPath('data.receipts.0.metadata.receipt_document.okud', '0330212');
        $receiveResponse->assertJsonPath('data.receipts.0.metadata.receipt_document.title', 'Товарная накладная');
        $receiveResponse->assertJsonPath('data.receipts.0.metadata.receipt_document.rows.0.name', 'Rebar A500');
        $receiveResponse->assertJsonPath('data.receipts.0.metadata.receipt_document.rows.0.quantity', 5);
        $receiveResponse->assertJsonPath('data.receipts.0.metadata.receipt_document.rows.0.price', 190);
        $receiveResponse->assertJsonPath('data.receipts.0.metadata.receipt_document.totals.amount_with_vat', 950);

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

        $receipt = PurchaseReceipt::query()
            ->where('purchase_order_id', $purchaseOrder->id)
            ->firstOrFail();

        $postedPdfResponse = $this->withHeaders($context->authHeaders())
            ->get("/api/v1/admin/procurement/purchase-orders/{$purchaseOrder->id}/receipts/{$receipt->id}/document/pdf");

        $postedPdfResponse->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $postedPdfResponse->headers->get('content-type'));
        $this->assertStringContainsString('torg12-', (string) $postedPdfResponse->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF', (string) $postedPdfResponse->getContent());

        $stockResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/warehouses/{$warehouse->id}/balances");

        $stockResponse->assertOk();
        $stockResponse->assertJsonPath('data.0.receipt_documents.0.receipt_id', $receipt->id);
        $stockResponse->assertJsonPath('data.0.receipt_documents.0.purchase_order_id', $purchaseOrder->id);
        $stockResponse->assertJsonPath('data.0.receipt_documents.0.receipt_number', $receipt->receipt_number);
        $stockResponse->assertJsonPath('data.0.receipt_documents.0.order_number', $purchaseOrder->order_number);

        $duplicateAcceptResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/procurement/proposals/{$secondProposal->id}/accept");

        $duplicateAcceptResponse->assertStatus(422);
        $this->assertSame(1, PurchaseOrder::query()
            ->where('accepted_supplier_proposal_id', $secondProposal->id)
            ->count());
    }

    public function test_purchase_order_receipt_number_is_unique_across_organizations(): void
    {
        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $unit = $this->createUnit($context->organization->id);
        $material = $this->createMaterial($context->organization->id, $unit->id);
        $purchaseRequest = $this->createPurchaseRequest($context->organization->id, $material->id);
        $supplier = $this->createSupplier($context->organization->id, 'Receipt Supplier', 'receipt@example.test');
        $warehouse = $this->createWarehouse($context->organization->id);
        $foreignWarehouse = $this->createWarehouse($foreignContext->organization->id);
        $foreignOrder = $this->createForeignPurchaseOrder($foreignContext);
        $existingNumber = 'PR-'.now()->format('Ym').'-0001';
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        PurchaseReceipt::query()->create([
            'organization_id' => $foreignContext->organization->id,
            'purchase_order_id' => $foreignOrder->id,
            'warehouse_id' => $foreignWarehouse->id,
            'receipt_number' => $existingNumber,
            'receipt_date' => now()->toDateString(),
        ]);

        $purchaseOrder = PurchaseOrder::query()->create([
            'organization_id' => $context->organization->id,
            'purchase_request_id' => $purchaseRequest->id,
            'supplier_id' => $supplier->id,
            'order_number' => 'PO-RECEIPT-'.uniqid(),
            'order_date' => now()->toDateString(),
            'status' => PurchaseOrderStatusEnum::IN_DELIVERY,
            'total_amount' => 500,
            'currency' => 'RUB',
        ]);

        $item = PurchaseOrderItem::query()->create([
            'purchase_order_id' => $purchaseOrder->id,
            'material_id' => $material->id,
            'material_name' => $material->name,
            'quantity' => 5,
            'unit' => 'pcs',
            'unit_price' => 100,
            'total_price' => 500,
        ]);
        $this->createPaidProcurementPaymentDocument($context, $purchaseOrder, 500);

        $receiveResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/procurement/purchase-orders/{$purchaseOrder->id}/receive-materials", [
                'warehouse_id' => $warehouse->id,
                'idempotency_key' => 'd1e0de99-d794-47fe-bf06-3df00abc541d',
                'items' => [
                    [
                        'item_id' => $item->id,
                        'quantity_received' => 5,
                        'price' => 100,
                    ],
                ],
            ]);

        $receiveResponse->assertOk();
        $this->assertNotSame($existingNumber, $receiveResponse->json('data.receipts.0.receipt_number'));
        $this->assertDatabaseHas('purchase_receipts', [
            'organization_id' => $context->organization->id,
            'purchase_order_id' => $purchaseOrder->id,
        ]);
    }

    public function test_purchase_order_receipt_document_pdf_can_be_downloaded_after_payment_before_posting(): void
    {
        $context = AdminApiTestContext::create();
        $unit = $this->createUnit($context->organization->id);
        $material = $this->createMaterial($context->organization->id, $unit->id);
        $purchaseRequest = $this->createPurchaseRequest($context->organization->id, $material->id);
        $supplier = $this->createSupplier($context->organization->id, 'PDF Supplier', 'pdf@example.test');
        $warehouse = $this->createWarehouse($context->organization->id);
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $purchaseOrder = PurchaseOrder::query()->create([
            'organization_id' => $context->organization->id,
            'purchase_request_id' => $purchaseRequest->id,
            'supplier_id' => $supplier->id,
            'order_number' => 'PO-PDF-'.uniqid(),
            'order_date' => now()->toDateString(),
            'status' => PurchaseOrderStatusEnum::CONFIRMED,
            'total_amount' => 500,
            'currency' => 'RUB',
        ]);

        $item = PurchaseOrderItem::query()->create([
            'purchase_order_id' => $purchaseOrder->id,
            'material_id' => $material->id,
            'material_name' => $material->name,
            'quantity' => 5,
            'unit' => 'pcs',
            'unit_price' => 100,
            'total_price' => 500,
        ]);
        $this->createPaidProcurementPaymentDocument($context, $purchaseOrder, 500);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/procurement/purchase-orders/{$purchaseOrder->id}/receipt-document/pdf", [
                'warehouse_id' => $warehouse->id,
                'idempotency_key' => 'd282ac33-39e0-4615-93b1-a3a8fa26928e',
                'receipt_date' => now()->toDateString(),
                'items' => [
                    [
                        'item_id' => $item->id,
                        'quantity_received' => 5,
                        'price' => 100,
                    ],
                ],
            ]);

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString('torg12-', (string) $response->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
        $this->assertSame(0, PurchaseReceipt::query()
            ->where('purchase_order_id', $purchaseOrder->id)
            ->count());
    }

    public function test_purchase_order_receipt_document_preview_preserves_included_vat_from_accepted_offer(): void
    {
        $context = AdminApiTestContext::create();
        $unit = $this->createUnit($context->organization->id);
        $material = $this->createMaterial($context->organization->id, $unit->id);
        $purchaseRequest = $this->createPurchaseRequest($context->organization->id, $material->id);
        $supplier = $this->createSupplier($context->organization->id, 'VAT Supplier', 'vat@example.test');
        $warehouse = $this->createWarehouse($context->organization->id);
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $purchaseOrder = PurchaseOrder::query()->create([
            'organization_id' => $context->organization->id,
            'purchase_request_id' => $purchaseRequest->id,
            'supplier_id' => $supplier->id,
            'order_number' => 'PO-VAT-'.uniqid(),
            'order_date' => now()->toDateString(),
            'status' => PurchaseOrderStatusEnum::CONFIRMED,
            'total_amount' => 2100,
            'currency' => 'RUB',
            'metadata' => [
                'commercial_snapshot' => [
                    'subtotal_amount' => '1800.00',
                    'delivery_amount' => '300.00',
                    'vat_mode' => 'included',
                    'vat_rate' => '20.00',
                    'vat_amount' => '350.00',
                    'total_amount' => '2100.00',
                ],
            ],
        ]);

        $item = PurchaseOrderItem::query()->create([
            'purchase_order_id' => $purchaseOrder->id,
            'material_id' => $material->id,
            'material_name' => $material->name,
            'quantity' => 100,
            'unit' => 'kg',
            'unit_price' => 18,
            'total_price' => 1800,
        ]);
        $this->createPaidProcurementPaymentDocument($context, $purchaseOrder, 2100);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/procurement/purchase-orders/{$purchaseOrder->id}/receipt-document/preview", [
                'warehouse_id' => $warehouse->id,
                'receipt_date' => now()->toDateString(),
                'items' => [
                    [
                        'item_id' => $item->id,
                        'quantity_received' => 100,
                        'price' => 18,
                    ],
                ],
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.rows.0.vat_rate', 20);
        $response->assertJsonPath('data.rows.0.amount_without_vat', 1500);
        $response->assertJsonPath('data.rows.0.vat_amount', 300);
        $response->assertJsonPath('data.rows.0.amount_with_vat', 1800);
        $response->assertJsonPath('data.totals.amount_without_vat', 1500);
        $response->assertJsonPath('data.totals.vat_amount', 300);
        $response->assertJsonPath('data.totals.amount_with_vat', 1800);
        $this->assertSame(0, PurchaseReceipt::query()
            ->where('purchase_order_id', $purchaseOrder->id)
            ->count());
    }

    public function test_purchase_order_receipt_requires_paid_payment_document(): void
    {
        $context = AdminApiTestContext::create();
        $unit = $this->createUnit($context->organization->id);
        $material = $this->createMaterial($context->organization->id, $unit->id);
        $purchaseRequest = $this->createPurchaseRequest($context->organization->id, $material->id);
        $supplier = $this->createSupplier($context->organization->id, 'Unpaid Supplier', 'unpaid@example.test');
        $warehouse = $this->createWarehouse($context->organization->id);
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $purchaseOrder = PurchaseOrder::query()->create([
            'organization_id' => $context->organization->id,
            'purchase_request_id' => $purchaseRequest->id,
            'supplier_id' => $supplier->id,
            'order_number' => 'PO-UNPAID-'.uniqid(),
            'order_date' => now()->toDateString(),
            'status' => PurchaseOrderStatusEnum::IN_DELIVERY,
            'total_amount' => 500,
            'currency' => 'RUB',
        ]);

        $item = PurchaseOrderItem::query()->create([
            'purchase_order_id' => $purchaseOrder->id,
            'material_id' => $material->id,
            'material_name' => $material->name,
            'quantity' => 5,
            'unit' => 'pcs',
            'unit_price' => 100,
            'total_price' => 500,
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/procurement/purchase-orders/{$purchaseOrder->id}/receive-materials", [
                'warehouse_id' => $warehouse->id,
                'idempotency_key' => '78ac1a98-6a13-4d25-87da-d2aa5b81980f',
                'receipt_date' => now()->toDateString(),
                'items' => [
                    [
                        'item_id' => $item->id,
                        'quantity_received' => 5,
                        'price' => 100,
                    ],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $response->assertJsonFragment([
            'message' => trans_message('procurement.purchase_orders.payment_required_before_receipt'),
        ]);
        $this->assertDatabaseMissing('purchase_receipts', [
            'organization_id' => $context->organization->id,
            'purchase_order_id' => $purchaseOrder->id,
        ]);
        $this->assertDatabaseMissing('warehouse_balances', [
            'organization_id' => $context->organization->id,
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
        ]);
        $pdfResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/procurement/purchase-orders/{$purchaseOrder->id}/receipt-document/pdf", [
                'warehouse_id' => $warehouse->id,
                'idempotency_key' => 'df8b2dbb-4ec4-4c42-aad9-6e0a76e16433',
                'receipt_date' => now()->toDateString(),
                'items' => [
                    [
                        'item_id' => $item->id,
                        'quantity_received' => 5,
                        'price' => 100,
                    ],
                ],
            ]);

        $pdfResponse->assertStatus(422);
        $pdfResponse->assertJsonFragment([
            'message' => trans_message('procurement.purchase_orders.payment_required_before_receipt'),
        ]);
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
        $partyService = app(SupplierPartyService::class);
        $supplierRequest->update([
            'supplier_party_id' => $partyService
                ->resolveRegisteredParty($context->organization->id, $supplier->id)
                ->id,
        ]);
        $foreignSupplierRequest->update([
            'supplier_party_id' => $partyService
                ->resolveRegisteredParty($foreignContext->organization->id, $foreignSupplier->id)
                ->id,
        ]);
        $purchaseRequestLine = $purchaseRequest->lines()->firstOrFail();
        $supplierRequest->lines()->create([
            'purchase_request_line_id' => $purchaseRequestLine->id,
            'material_id' => $purchaseRequestLine->material_id,
            'name' => $purchaseRequestLine->name,
            'quantity' => $purchaseRequestLine->quantity,
            'unit' => $purchaseRequestLine->unit,
        ]);
        $foreignPurchaseRequestLine = $foreignPurchaseRequest->lines()->firstOrFail();
        $foreignSupplierRequest->lines()->create([
            'purchase_request_line_id' => $foreignPurchaseRequestLine->id,
            'material_id' => $foreignPurchaseRequestLine->material_id,
            'name' => $foreignPurchaseRequestLine->name,
            'quantity' => $foreignPurchaseRequestLine->quantity,
            'unit' => $foreignPurchaseRequestLine->unit,
        ]);
        app(SupplierRequestVersionService::class)->createSentVersion($supplierRequest, $context->user->id);
        app(SupplierRequestVersionService::class)->createSentVersion(
            $foreignSupplierRequest,
            $foreignContext->user->id,
        );

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

    public function test_purchase_order_receipt_materializes_free_text_material_before_warehouse_posting(): void
    {
        $context = AdminApiTestContext::create();
        $supplier = $this->createSupplier($context->organization->id, 'Free Text Supplier', 'free-text@example.test');
        $warehouse = $this->createWarehouse($context->organization->id);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $siteRequest = SiteRequest::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'user_id' => $context->user->id,
            'title' => 'Free text material request',
            'status' => SiteRequestStatusEnum::APPROVED,
            'request_type' => 'material_request',
            'priority' => 'medium',
            'material_name' => 'Custom free-text material',
            'material_quantity' => 5,
            'material_unit' => 'pcs',
        ]);
        $purchaseRequest = $this->createFreeTextPurchaseRequest($context->organization->id, $siteRequest->id);
        $this->createUnit($context->organization->id);
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $bulkResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/procurement/supplier-requests/bulk', [
                'purchase_request_id' => $purchaseRequest->id,
                'send_immediately' => true,
                'suppliers' => [
                    ['supplier_id' => $supplier->id],
                ],
            ]);

        $bulkResponse->assertCreated();

        $supplierRequest = SupplierRequest::query()
            ->where('purchase_request_id', $purchaseRequest->id)
            ->firstOrFail();
        $proposal = $this->createProposalThroughApi($context, $supplierRequest, 700.0);

        $decisionResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/procurement/purchase-requests/{$purchaseRequest->id}/proposal-decision", [
                'supplier_proposal_id' => $proposal->id,
            ]);

        $decisionResponse->assertOk();
        $this->assertSame(SupplierProposalStatusEnum::ACCEPTED, $proposal->fresh()->status);

        $purchaseOrder = PurchaseOrder::query()
            ->where('accepted_supplier_proposal_id', $proposal->id)
            ->with('items')
            ->firstOrFail();
        $item = $purchaseOrder->items()->firstOrFail();
        $this->assertNull($item->material_id);
        $this->createPaidProcurementPaymentDocument($context, $purchaseOrder, 700);

        $receiveResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/procurement/purchase-orders/{$purchaseOrder->id}/receive-materials", [
                'warehouse_id' => $warehouse->id,
                'idempotency_key' => '7b434ade-a9de-48a2-8577-269217fd5cc2',
                'items' => [
                    [
                        'item_id' => $item->id,
                        'quantity_received' => 5,
                        'price' => 140,
                    ],
                ],
            ]);

        $receiveResponse->assertOk();
        $receiveResponse->assertJsonPath('data.status', PurchaseOrderStatusEnum::DELIVERED->value);

        $item->refresh();
        $siteRequest->refresh();

        $this->assertNotNull($item->material_id);
        $this->assertSame($item->material_id, $siteRequest->material_id);
        $this->assertSame(SiteRequestStatusEnum::COMPLETED, $siteRequest->status);
        $this->assertDatabaseHas('materials', [
            'id' => $item->material_id,
            'organization_id' => $context->organization->id,
            'name' => 'Custom free-text material',
        ]);
        $this->assertDatabaseHas('warehouse_balances', [
            'organization_id' => $context->organization->id,
            'warehouse_id' => $warehouse->id,
            'material_id' => $item->material_id,
        ]);
    }

    public function test_external_supplier_offer_can_create_procurement_contract(): void
    {
        $context = AdminApiTestContext::create();
        $unit = $this->createUnit($context->organization->id);
        $material = $this->createMaterial($context->organization->id, $unit->id);
        $purchaseRequest = $this->createPurchaseRequest($context->organization->id, $material->id);
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $bulkResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/procurement/supplier-requests/bulk', [
                'purchase_request_id' => $purchaseRequest->id,
                'send_immediately' => true,
                'suppliers' => [
                    [
                        'external_supplier' => [
                            'name' => 'External Concrete Supplier',
                            'contact_person' => 'External Contact',
                            'phone' => '+7 999 000-00-01',
                            'email' => 'external-concrete@example.test',
                            'tax_number' => '7712345678',
                            'address' => 'External supplier address',
                        ],
                    ],
                ],
            ]);

        $bulkResponse->assertCreated();

        $supplierRequest = SupplierRequest::query()
            ->where('purchase_request_id', $purchaseRequest->id)
            ->with(['lines'])
            ->firstOrFail();
        $line = $supplierRequest->lines()->firstOrFail();
        $publicProposalResponse = $this->withHeader('Origin', 'https://1мост.рф')->postJson(
            "/api/v1/procurement/supplier-requests/{$supplierRequest->public_token}/proposals",
            [
                'subtotal_amount' => 900,
                'delivery_amount' => 0,
                'vat_amount' => 0,
                'total_amount' => 900,
                'currency' => 'RUB',
                'vat_mode' => 'included',
                'vat_rate' => 20,
                'valid_until' => now()->addDays(10)->toDateString(),
                'delivery_due_date' => now()->addDays(5)->toDateString(),
                'payment_terms' => 'Payment after delivery',
                'delivery_terms' => 'Delivery to warehouse',
                'items' => [
                    [
                        'supplier_request_line_id' => $line->id,
                        'name' => $line->name,
                        'quantity' => 5,
                        'unit' => $line->unit,
                        'unit_price' => 180,
                        'total_amount' => 900,
                    ],
                ],
            ]
        );

        $publicProposalResponse->assertCreated();
        $proposal = SupplierProposal::query()
            ->where('supplier_request_id', $supplierRequest->id)
            ->firstOrFail();

        $decisionResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/procurement/purchase-requests/{$purchaseRequest->id}/proposal-decision", [
                'supplier_proposal_id' => $proposal->id,
            ]);

        $decisionResponse->assertOk();
        $this->assertSame(SupplierProposalStatusEnum::ACCEPTED, $proposal->fresh()->status);

        $purchaseOrder = PurchaseOrder::query()
            ->where('accepted_supplier_proposal_id', $proposal->id)
            ->with('items')
            ->firstOrFail();
        $warehouse = $this->createWarehouse($context->organization->id);
        $item = $purchaseOrder->items()->firstOrFail();
        $this->createPaidProcurementPaymentDocument($context, $purchaseOrder, 900);

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/procurement/purchase-orders/{$purchaseOrder->id}/mark-in-delivery")
            ->assertOk();

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/procurement/purchase-orders/{$purchaseOrder->id}/receive-materials", [
                'warehouse_id' => $warehouse->id,
                'idempotency_key' => '3ab17910-8257-4a4c-96b0-518ed2d6bc9b',
                'receipt_date' => now()->toDateString(),
                'items' => [
                    [
                        'item_id' => $item->id,
                        'quantity_received' => 5,
                        'price' => 180,
                    ],
                ],
            ])
            ->assertOk();

        $this->enableImmutableAuditWriter();

        $contractResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/procurement/purchase-orders/{$purchaseOrder->id}/create-contract");

        $contractResponse->assertCreated();
        $contractResponse->assertJsonPath('data.contract.supplier_id', null);
        $contractResponse->assertJsonPath('data.contract.contractor.name', 'External Concrete Supplier');

        $contractId = $contractResponse->json('data.contract.id');
        $contractorId = $contractResponse->json('data.contract.contractor_id');

        $this->assertNotNull($contractorId);
        $this->assertDatabaseHas('contracts', [
            'id' => $contractId,
            'organization_id' => $context->organization->id,
            'supplier_id' => null,
            'contractor_id' => $contractorId,
            'contract_category' => 'procurement',
        ]);
        $this->assertDatabaseHas('contractors', [
            'id' => $contractorId,
            'organization_id' => $context->organization->id,
            'name' => 'External Concrete Supplier',
            'inn' => '7712345678',
            'contractor_type' => Contractor::TYPE_MANUAL,
        ]);

        $paymentResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/payments/documents', [
                'document_type' => 'payment_request',
                'document_date' => now()->toDateString(),
                'due_date' => now()->addDays(7)->toDateString(),
                'invoice_type' => 'material_purchase',
                'direction' => 'outgoing',
                'amount' => 900,
                'currency' => 'RUB',
                'contract_id' => $contractId,
                'payment_purpose' => 'Payment for delivered materials',
            ]);

        $paymentResponse->assertCreated();
        $paymentId = $paymentResponse->json('data.id');
        $this->assertDatabaseHas('payment_documents', [
            'id' => $paymentId,
            'organization_id' => $context->organization->id,
            'source_type' => \App\Models\Contract::class,
            'source_id' => $contractId,
            'invoice_type' => 'material_purchase',
            'payer_organization_id' => $context->organization->id,
            'payee_contractor_id' => $contractorId,
            'amount' => 900,
        ]);
        $this->assertSame($contractId, PaymentDocument::query()->findOrFail($paymentId)->source_id);
    }

    public function test_validated_upd_is_attached_to_the_same_transaction_as_purchase_receipt(): void
    {
        [$context, $purchaseOrder, $item, $warehouse] = $this->createIncomingUpdReceiptScenario();

        $uploadResponse = $this->withHeaders($context->authHeaders())
            ->post("/api/v1/admin/procurement/purchase-orders/{$purchaseOrder->id}/receipt-documents/upd", [
                'file' => UploadedFile::fake()->createWithContent(
                    self::INCOMING_UPD_FILE_ID.'.xml',
                    $this->validIncomingUpdXml(),
                ),
            ]);

        $uploadResponse->assertCreated();
        $uploadResponse->assertJsonPath('data.is_valid', true);
        $documentId = (int) $uploadResponse->json('data.id');

        $receiveResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/procurement/purchase-orders/{$purchaseOrder->id}/receive-materials", [
                'warehouse_id' => $warehouse->id,
                'idempotency_key' => '07aee87f-ab27-4b51-90d7-53f967a91e5d',
                'document_mode' => 'upd_xml',
                'receipt_document_id' => $documentId,
                'items' => [[
                    'item_id' => $item->id,
                    'quantity_received' => 100,
                    'price' => 15,
                ]],
            ]);

        $receiveResponse->assertOk();
        $receiveResponse->assertJsonPath('data.receipts.0.metadata.receipt_document.document_type', 'upd_xml');
        $receiveResponse->assertJsonPath('data.receipts.0.document.id', $documentId);
        $receiveResponse->assertJsonPath('data.receipts.0.document.status', PurchaseReceiptDocumentStatusEnum::ATTACHED->value);
        $this->assertDatabaseHas('purchase_receipt_documents', [
            'id' => $documentId,
            'purchase_order_id' => $purchaseOrder->id,
            'status' => PurchaseReceiptDocumentStatusEnum::ATTACHED->value,
        ]);
        $this->assertSame(
            PurchaseReceipt::query()->where('purchase_order_id', $purchaseOrder->id)->value('id'),
            PurchaseReceiptDocument::query()->findOrFail($documentId)->purchase_receipt_id,
        );

        $stockResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/warehouses/{$warehouse->id}/balances");

        $stockResponse->assertOk();
        $stockResponse->assertJsonPath('data.0.receipt_documents.0.document_type', 'upd_xml');
        $stockResponse->assertJsonPath('data.0.receipt_documents.0.document_id', $documentId);
        $stockResponse->assertJsonPath('data.0.receipt_documents.0.document_number', 'УПД-2026-1');
        $stockResponse->assertJsonPath('data.0.receipt_documents.0.filename', self::INCOMING_UPD_FILE_ID.'.xml');
        $stockResponse->assertJsonPath('data.0.receipt_documents.0.has_pdf', false);
    }

    public function test_upd_with_different_receipt_items_rolls_back_receipt_and_stock_changes(): void
    {
        [$context, $purchaseOrder, $item, $warehouse, $material] = $this->createIncomingUpdReceiptScenario();

        $uploadResponse = $this->withHeaders($context->authHeaders())
            ->post("/api/v1/admin/procurement/purchase-orders/{$purchaseOrder->id}/receipt-documents/upd", [
                'file' => UploadedFile::fake()->createWithContent(
                    self::INCOMING_UPD_FILE_ID.'.xml',
                    $this->validIncomingUpdXml(),
                ),
            ]);
        $uploadResponse->assertCreated();
        $documentId = (int) $uploadResponse->json('data.id');

        $receiveResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/procurement/purchase-orders/{$purchaseOrder->id}/receive-materials", [
                'warehouse_id' => $warehouse->id,
                'idempotency_key' => 'f31ee143-edf9-4af8-a60a-a12d3748a052',
                'document_mode' => 'upd_xml',
                'receipt_document_id' => $documentId,
                'items' => [[
                    'item_id' => $item->id,
                    'quantity_received' => 99,
                    'price' => 15,
                ]],
            ]);

        $receiveResponse->assertStatus(422);
        $receiveResponse->assertJsonPath(
            'message',
            trans_message('procurement.upd.attachment_issues.receipt_items_mismatch'),
        );
        $this->assertDatabaseMissing('purchase_receipts', [
            'purchase_order_id' => $purchaseOrder->id,
        ]);
        $this->assertDatabaseMissing('warehouse_balances', [
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
        ]);
        $this->assertDatabaseHas('purchase_receipt_documents', [
            'id' => $documentId,
            'purchase_receipt_id' => null,
            'status' => PurchaseReceiptDocumentStatusEnum::VALIDATED->value,
        ]);
    }

    public function test_purchase_order_payload_prefers_supplier_snapshot_from_offer(): void
    {
        $context = AdminApiTestContext::create();
        $supplier = $this->createSupplier($context->organization->id, 'Catalog Supplier', 'catalog@example.test');
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $purchaseOrder = PurchaseOrder::query()->create([
            'organization_id' => $context->organization->id,
            'supplier_id' => $supplier->id,
            'order_number' => 'PO-SNAPSHOT-'.uniqid(),
            'order_date' => now()->toDateString(),
            'status' => PurchaseOrderStatusEnum::CONFIRMED,
            'total_amount' => 1500,
            'currency' => 'RUB',
            'supplier_snapshot' => [
                'type' => 'external',
                'display_name' => 'Supplier From Offer',
                'tax_id' => '7711999900',
                'registered_supplier_id' => null,
            ],
        ]);

        $indexResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/procurement/purchase-orders?per_page=20');

        $indexResponse->assertOk();
        $indexResponse->assertJsonPath('data.0.supplier.id', null);
        $indexResponse->assertJsonPath('data.0.supplier.name', 'Supplier From Offer');
        $indexResponse->assertJsonPath('data.0.supplier.inn', '7711999900');

        $showResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/procurement/purchase-orders/{$purchaseOrder->id}");

        $showResponse->assertOk();
        $showResponse->assertJsonPath('data.supplier.id', null);
        $showResponse->assertJsonPath('data.supplier.name', 'Supplier From Offer');
        $showResponse->assertJsonPath('data.supplier.inn', '7711999900');
    }

    /**
     * @return array{AdminApiTestContext, PurchaseOrder, PurchaseOrderItem, OrganizationWarehouse, Material}
     */
    private function createIncomingUpdReceiptScenario(): array
    {
        $context = AdminApiTestContext::create();
        $context->organization->forceFill(['tax_number' => '1654321098'])->save();
        $unit = MeasurementUnit::query()
            ->where('organization_id', $context->organization->id)
            ->where('short_name', 'кг')
            ->firstOrFail();
        $material = $this->createMaterial($context->organization->id, $unit->id);
        $material->forceFill(['name' => 'Цемент М500, мешок 50 кг'])->save();
        $purchaseRequest = $this->createPurchaseRequest($context->organization->id, $material->id);
        $supplier = $this->createSupplier($context->organization->id, 'ООО Поставщик', 'upd@example.test');
        $warehouse = $this->createWarehouse($context->organization->id);

        $purchaseOrder = PurchaseOrder::query()->create([
            'organization_id' => $context->organization->id,
            'purchase_request_id' => $purchaseRequest->id,
            'supplier_id' => $supplier->id,
            'order_number' => 'PO-UPD-'.uniqid(),
            'order_date' => now()->toDateString(),
            'status' => PurchaseOrderStatusEnum::IN_DELIVERY,
            'total_amount' => 1500,
            'currency' => 'RUB',
            'supplier_snapshot' => [
                'type' => 'registered',
                'display_name' => 'ООО Поставщик',
                'tax_id' => '7712345678',
            ],
        ]);
        $item = PurchaseOrderItem::query()->create([
            'purchase_order_id' => $purchaseOrder->id,
            'material_id' => $material->id,
            'material_name' => $material->name,
            'quantity' => 100,
            'unit' => 'кг',
            'unit_price' => 15,
            'total_price' => 1500,
        ]);

        $this->createPaidProcurementPaymentDocument($context, $purchaseOrder, 1500);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $this->mock(FileService::class, static function (MockInterface $mock): void {
            $mock->shouldReceive('putPrivate')
                ->andReturnUsing(static fn (string $key, string $contents, string $mime, string $sha256): CurrentStoredFile => new CurrentStoredFile(
                    $key,
                    'test-etag',
                    strlen($contents),
                    $sha256,
                    $mime,
                ));
        });

        return [$context, $purchaseOrder, $item, $warehouse, $material];
    }

    private function validIncomingUpdXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Файл ИдФайл="ON_NSCHFDOPPR_2BM-7712345678-771201001-20260904-1_2BM-1654321098-165401001-20260904-1_20260904_1" ВерсФорм="5.03" ВерсПрог="МОСТ">
  <Документ КНД="1115131" Функция="ДОП" НаимДокОпр="Универсальный передаточный документ" ДатаИнфПр="04.09.2026" ВремИнфПр="12.00.00">
    <СвСчФакт НомерДок="УПД-2026-1" ДатаДок="04.09.2026">
      <СвПрод><ИдСв><СвЮЛУч НаимОрг="ООО Поставщик" ИННЮЛ="7712345678" КПП="771201001"/></ИдСв></СвПрод>
      <СвПокуп><ИдСв><СвЮЛУч НаимОрг="ООО МОСТ" ИННЮЛ="1654321098" КПП="165401001"/></ИдСв></СвПокуп>
      <ДенИзм КодОКВ="643" НаимОКВ="Российский рубль"/>
    </СвСчФакт>
    <ТаблСчФакт>
      <СведТов НомСтр="1" НаимТов="Цемент М500, мешок 50 кг" ОКЕИ_Тов="166" НаимЕдИзм="кг" КолТов="100" ЦенаТов="15.00" СтТовБезНДС="1500.00" НалСт="20%" СтТовУчНал="1800.00">
        <Акциз><БезАкциз>без акциза</БезАкциз></Акциз>
        <СумНал><СумНал>300.00</СумНал></СумНал>
      </СведТов>
      <ВсегоОпл СтТовБезНДСВсего="1500.00" СтТовУчНалВсего="1800.00"><СумНалВсего><СумНал>300.00</СумНал></СумНалВсего></ВсегоОпл>
    </ТаблСчФакт>
    <СвПродПер><СвПер СодОпер="Товары переданы" ДатаПер="04.09.2026"><БезДокОснПер>1</БезДокОснПер></СвПер></СвПродПер>
    <Подписант СпосПодтПолном="1"><ФИО Фамилия="Иванов" Имя="Иван" Отчество="Иванович"/></Подписант>
  </Документ>
</Файл>
XML;
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
            'vat_rate' => 20,
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
            'request_number' => 'PR-FLOW-'.$organizationId.'-'.uniqid(),
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

    private function createFreeTextPurchaseRequest(int $organizationId, ?int $siteRequestId = null): PurchaseRequest
    {
        $purchaseRequest = PurchaseRequest::query()->create([
            'organization_id' => $organizationId,
            'site_request_id' => $siteRequestId,
            'request_number' => 'PR-FREE-'.$organizationId.'-'.uniqid(),
            'status' => PurchaseRequestStatusEnum::APPROVED,
            'budget_currency' => 'RUB',
        ]);

        PurchaseRequestLine::query()->create([
            'purchase_request_id' => $purchaseRequest->id,
            'material_id' => null,
            'name' => 'Custom free-text material',
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
            'order_number' => 'PO-FOR-'.uniqid(),
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
            'code' => 'REB-FLOW-'.$organizationId.'-'.uniqid(),
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
            'code' => 'WH-FLOW-'.$organizationId,
            'warehouse_type' => OrganizationWarehouse::TYPE_CENTRAL,
            'is_main' => true,
            'is_active' => true,
        ]);
    }

    private function createPaidProcurementPaymentDocument(
        AdminApiTestContext $context,
        PurchaseOrder $purchaseOrder,
        float $amount
    ): PaymentDocument {
        return PaymentDocument::query()->create([
            'organization_id' => $context->organization->id,
            'document_type' => PaymentDocumentType::PAYMENT_ORDER,
            'document_number' => 'PAY-PO-'.$purchaseOrder->id.'-'.uniqid(),
            'document_date' => now()->toDateString(),
            'direction' => InvoiceDirection::OUTGOING,
            'invoice_type' => InvoiceType::MATERIAL_PURCHASE,
            'payer_organization_id' => $context->organization->id,
            'amount' => $amount,
            'currency' => 'RUB',
            'paid_amount' => $amount,
            'remaining_amount' => 0,
            'status' => PaymentDocumentStatus::PAID,
            'paid_at' => now(),
            'metadata' => [
                'purchase_order_id' => $purchaseOrder->id,
                'purchase_order_number' => $purchaseOrder->order_number,
            ],
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
