<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\InvoiceType;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\ProjectMaterialDelivery;
use App\BusinessModules\Features\Procurement\Enums\PurchaseOrderStatusEnum;
use App\BusinessModules\Features\Procurement\Enums\PurchaseRequestStatusEnum;
use App\BusinessModules\Features\Procurement\Enums\SupplierProposalStatusEnum;
use App\BusinessModules\Features\Procurement\Enums\SupplierRequestStatusEnum;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrderItem;
use App\BusinessModules\Features\Procurement\Models\PurchaseReceipt;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequestLine;
use App\BusinessModules\Features\Procurement\Models\SupplierProposal;
use App\BusinessModules\Features\Procurement\Models\SupplierRequest;
use App\BusinessModules\Features\Procurement\Services\ProcurementChainService;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestStatusEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestTypeEnum;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\Models\Material;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProcurementChainServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_material_site_request_without_fulfillment_decision_points_to_source_selection(): void
    {
        $organization = Organization::factory()->create();
        $siteRequest = $this->createSiteRequest($organization);

        $summary = app(ProcurementChainService::class)->forSiteRequest($siteRequest);

        $this->assertSame('fulfillment_source_required', $summary->currentStage->key);
        $this->assertSame('determine_fulfillment_source', $summary->nextAction?->key);
        $this->assertTrue($summary->compact()->isBlocked);
        $this->assertSame('/site-requests/'.$siteRequest->id.'?fulfillment=1', $summary->nextAction?->href);
        $this->assertSame('fulfillment_source_not_selected', $summary->blockers->first()?->key);
        $this->assertSame('site_request', $summary->root['type']);
        $this->assertSame($siteRequest->id, $summary->root['id']);
        $this->assertSame(['site_request'], $summary->linkedDocuments->pluck('type')->all());
    }

    public function test_malformed_fulfillment_decision_does_not_open_purchase_request_creation(): void
    {
        $organization = Organization::factory()->create();
        $siteRequest = $this->createSiteRequest($organization);
        $siteRequest->update([
            'metadata' => [
                'fulfillment_decision' => [
                    'source' => 'purchase',
                    'purchase_quantity' => 5,
                ],
            ],
        ]);

        $summary = app(ProcurementChainService::class)->forSiteRequest($siteRequest->fresh());

        $this->assertSame('fulfillment_source_required', $summary->currentStage->key);
        $this->assertSame('determine_fulfillment_source', $summary->nextAction?->key);
        $this->assertSame('fulfillment_source_not_selected', $summary->blockers->first()?->key);
    }

    public function test_approved_purchase_request_without_supplier_request_points_to_supplier_request_creation(): void
    {
        $organization = Organization::factory()->create();
        $siteRequest = $this->createSiteRequest($organization);
        $purchaseRequest = $this->createPurchaseRequest($organization, $siteRequest);

        $summary = app(ProcurementChainService::class)->forPurchaseRequest($purchaseRequest);

        $this->assertSame('purchase_request_approved', $summary->currentStage->key);
        $this->assertSame('create_supplier_request', $summary->nextAction?->key);
        $this->assertSame('/procurement?tab=supplier_requests&purchase_request_id='.$purchaseRequest->id, $summary->nextAction?->href);
        $this->assertSame(
            ['site_request', 'purchase_request'],
            $summary->linkedDocuments->pluck('type')->all()
        );
    }

    public function test_pending_purchase_request_action_points_to_approval_post_endpoint(): void
    {
        $organization = Organization::factory()->create();
        $purchaseRequest = $this->createPurchaseRequest($organization);
        $purchaseRequest->update(['status' => PurchaseRequestStatusEnum::PENDING]);

        $summary = app(ProcurementChainService::class)->forPurchaseRequest($purchaseRequest->fresh());

        $this->assertSame('purchase_request_created', $summary->currentStage->key);
        $this->assertSame('approve_purchase_request', $summary->nextAction?->key);
        $this->assertSame('POST', $summary->nextAction?->method);
        $this->assertSame(
            "/api/v1/admin/procurement/purchase-requests/{$purchaseRequest->id}/approve",
            $summary->nextAction?->href
        );
    }

    public function test_draft_supplier_request_action_points_to_send_post_endpoint(): void
    {
        $organization = Organization::factory()->create();
        $purchaseRequest = $this->createPurchaseRequest($organization);
        $supplierRequest = $this->createSupplierRequest($purchaseRequest, SupplierRequestStatusEnum::DRAFT);

        $summary = app(ProcurementChainService::class)->forPurchaseRequest($purchaseRequest->fresh());

        $this->assertSame('supplier_request_created', $summary->currentStage->key);
        $this->assertSame('send_supplier_request', $summary->nextAction?->key);
        $this->assertSame('POST', $summary->nextAction?->method);
        $this->assertSame(
            "/api/v1/admin/procurement/supplier-requests/{$supplierRequest->id}/send",
            $summary->nextAction?->href
        );
    }

    public function test_received_supplier_proposals_action_points_to_purchase_request_comparison(): void
    {
        $organization = Organization::factory()->create();
        $purchaseRequest = $this->createPurchaseRequest($organization);
        $supplierRequest = $this->createSupplierRequest($purchaseRequest, SupplierRequestStatusEnum::SENT);
        $this->createSupplierProposal($supplierRequest);

        $summary = app(ProcurementChainService::class)->forPurchaseRequest($purchaseRequest->fresh());

        $this->assertSame('commercial_proposal_received', $summary->currentStage->key);
        $this->assertSame('select_proposal', $summary->nextAction?->key);
        $this->assertSame('/procurement/proposals/compare?purchase_request_id='.$purchaseRequest->id, $summary->nextAction?->href);
    }

    public function test_sent_supplier_request_without_proposals_waits_without_enabled_action(): void
    {
        $organization = Organization::factory()->create();
        $purchaseRequest = $this->createPurchaseRequest($organization);
        $this->createSupplierRequest($purchaseRequest, SupplierRequestStatusEnum::SENT);

        $summary = app(ProcurementChainService::class)->forPurchaseRequest($purchaseRequest->fresh());

        $this->assertSame('supplier_request_sent', $summary->currentStage->key);
        $this->assertSame('wait_for_proposals', $summary->nextAction?->key);
        $this->assertFalse($summary->nextAction?->isEnabled);
        $this->assertNotNull($summary->nextAction?->disabledReason);
    }

    public function test_order_chain_links_the_accepted_proposal_in_a_five_supplier_contest(): void
    {
        $organization = Organization::factory()->create();
        $purchaseRequest = $this->createPurchaseRequest($organization);
        $winningRequest = $this->createSupplierRequest($purchaseRequest, SupplierRequestStatusEnum::SENT);
        $winner = $this->createSupplierProposal($winningRequest);
        $winner->update(['status' => SupplierProposalStatusEnum::ACCEPTED]);

        for ($index = 1; $index <= 4; $index++) {
            $competitorRequest = $winningRequest->replicate();
            $competitorRequest->request_number .= '-'.$index;
            $competitorRequest->public_token .= '-'.$index;
            $competitorRequest->save();
            $this->createSupplierProposal($competitorRequest);
        }

        $order = $this->createPurchaseOrder($purchaseRequest, PurchaseOrderStatusEnum::CONFIRMED);
        $order->update(['accepted_supplier_proposal_id' => $winner->id]);

        $summary = app(ProcurementChainService::class)->forPurchaseOrder($order->fresh());

        $this->assertCount(5, $summary->linkedDocuments->where('type', 'supplier_proposal'));
        $this->assertSame($winner->id, $summary->stages->firstWhere('key', 'proposal_selected')?->document?->id);
        $this->assertSame($winningRequest->id, $summary->stages->firstWhere('key', 'supplier_request_sent')?->document?->id);
    }

    public function test_confirmed_order_without_payment_points_to_payment_document(): void
    {
        $organization = Organization::factory()->create();
        $purchaseRequest = $this->createPurchaseRequest($organization);
        $purchaseOrder = $this->createPurchaseOrder($purchaseRequest, PurchaseOrderStatusEnum::CONFIRMED);

        $summary = app(ProcurementChainService::class)->forPurchaseOrder($purchaseOrder);

        $this->assertSame('payment_document_missing', $summary->currentStage->key);
        $this->assertSame('create_or_open_payment_document', $summary->nextAction?->key);
        $this->assertSame('payment_document_missing', $summary->blockers->first()?->key);
        $this->assertTrue($summary->compact()->isBlocked);
    }

    public function test_purchase_delivery_does_not_claim_warehouse_fulfillment(): void
    {
        $organization = Organization::factory()->create();
        $siteRequest = $this->createSiteRequest($organization);
        $purchaseRequest = $this->createPurchaseRequest($organization, $siteRequest);
        $order = $this->createPurchaseOrder($purchaseRequest, PurchaseOrderStatusEnum::CONFIRMED);
        $this->createMaterialDelivery($siteRequest, 'purchase');

        $summary = app(ProcurementChainService::class)->forPurchaseOrder($order);

        foreach (['warehouse_reserved', 'warehouse_in_transit', 'project_material_accepted'] as $key) {
            $this->assertNull($summary->stages->firstWhere('key', $key));
        }
        $this->assertCount(1, $summary->linkedDocuments->where('type', 'project_material_delivery'));
    }

    public function test_parallel_warehouse_delivery_uses_its_actual_progress(): void
    {
        $organization = Organization::factory()->create();
        $siteRequest = $this->createSiteRequest($organization);
        $purchaseRequest = $this->createPurchaseRequest($organization, $siteRequest);
        $order = $this->createPurchaseOrder($purchaseRequest, PurchaseOrderStatusEnum::CONFIRMED);
        $delivery = $this->createMaterialDelivery($siteRequest, 'warehouse');

        foreach ([
            'processing' => ['pending', 'pending', 'pending'],
            'reserved' => ['done', 'pending', 'pending'],
            'in_transit' => ['done', 'current', 'pending'],
            'accepted' => ['done', 'done', 'done'],
        ] as $status => $expected) {
            $delivery->update(['status' => $status]);
            $summary = app(ProcurementChainService::class)->forPurchaseOrder($order->fresh());
            foreach (['warehouse_reserved', 'warehouse_in_transit', 'project_material_accepted'] as $index => $key) {
                $this->assertSame($expected[$index], $summary->stages->firstWhere('key', $key)?->status, $status.': '.$key);
            }
        }
    }

    public function test_unfinished_warehouse_delivery_remains_actionable_after_a_newer_delivery_is_accepted(): void
    {
        $organization = Organization::factory()->create();
        $siteRequest = $this->createSiteRequest($organization);
        $unfinished = $this->createMaterialDelivery($siteRequest, 'warehouse');
        $accepted = $this->createMaterialDelivery($siteRequest, 'warehouse');
        $accepted->update(['status' => 'accepted']);
        $cancelled = $this->createMaterialDelivery($siteRequest, 'warehouse');
        $cancelled->update(['status' => 'cancelled']);
        $this->createMaterialDelivery($siteRequest, 'purchase');

        foreach (['processing' => 'warehouse_reserved', 'in_transit' => 'warehouse_in_transit'] as $status => $stage) {
            $unfinished->update(['status' => $status]);
            $summary = app(ProcurementChainService::class)->forSiteRequest($siteRequest->fresh());

            $this->assertSame($stage, $summary->currentStage->key);
            $this->assertSame('/warehouse?tab=project-material-deliveries&delivery_id='.$unfinished->id, $summary->nextAction?->href);
            foreach (['warehouse_reserved', 'warehouse_in_transit', 'project_material_accepted'] as $key) {
                $this->assertSame($unfinished->id, $summary->stages->firstWhere('key', $key)?->document?->id);
            }
        }

        $unfinished->update(['status' => 'accepted']);
        $summary = app(ProcurementChainService::class)->forSiteRequest($siteRequest->fresh());
        $this->assertSame('project_material_accepted', $summary->currentStage->key);
        $this->assertNull($summary->nextAction);
        foreach (['warehouse_reserved', 'warehouse_in_transit', 'project_material_accepted'] as $key) {
            $this->assertSame('done', $summary->stages->firstWhere('key', $key)?->status);
            $this->assertSame($accepted->id, $summary->stages->firstWhere('key', $key)?->document?->id);
        }
    }

    private function createMaterialDelivery(SiteRequest $siteRequest, string $source): ProjectMaterialDelivery
    {
        return ProjectMaterialDelivery::query()->create([
            'organization_id' => $siteRequest->organization_id,
            'project_id' => $siteRequest->project_id,
            'site_request_id' => $siteRequest->id,
            'material_id' => Material::query()->create([
                'organization_id' => $siteRequest->organization_id,
                'name' => 'Арматура',
                'code' => 'CHAIN-MATERIAL-'.uniqid(),
                'default_price' => 100,
                'is_active' => true,
            ])->id,
            'source_type' => $source,
            'status' => 'processing',
            'requested_quantity' => 5,
        ]);
    }

    public function test_draft_payment_document_points_to_submission(): void
    {
        $organization = Organization::factory()->create();
        $purchaseRequest = $this->createPurchaseRequest($organization);
        $purchaseOrder = $this->createPurchaseOrder($purchaseRequest, PurchaseOrderStatusEnum::CONFIRMED);
        $paymentDocument = $this->createPaymentDocument($purchaseOrder, PaymentDocumentStatus::DRAFT, 0.0);

        $summary = app(ProcurementChainService::class)->forPurchaseOrder($purchaseOrder);

        $this->assertSame('payment_document_draft', $summary->currentStage->key);
        $this->assertSame('submit_payment_document', $summary->nextAction?->key);
        $this->assertSame('POST', $summary->nextAction?->method);
        $this->assertSame(
            "/api/v1/admin/payments/documents/{$paymentDocument->id}/submit",
            $summary->nextAction?->href
        );
        $this->assertSame(
            "/payments?tab=documents&document_id={$paymentDocument->id}",
            $summary->linkedDocuments->firstWhere('type', 'payment_document')?->href
        );
        $this->assertSame('payment_document_not_submitted', $summary->blockers->first()?->key);
    }

    public function test_submitted_payment_document_points_to_approval(): void
    {
        $organization = Organization::factory()->create();
        $purchaseRequest = $this->createPurchaseRequest($organization);
        $purchaseOrder = $this->createPurchaseOrder($purchaseRequest, PurchaseOrderStatusEnum::CONFIRMED);
        $paymentDocument = $this->createPaymentDocument($purchaseOrder, PaymentDocumentStatus::PENDING_APPROVAL, 0.0);

        $summary = app(ProcurementChainService::class)->forPurchaseOrder($purchaseOrder);

        $this->assertSame('payment_approval_required', $summary->currentStage->key);
        $this->assertSame('approve_payment_document', $summary->nextAction?->key);
        $this->assertSame('POST', $summary->nextAction?->method);
        $this->assertSame(
            "/api/v1/admin/payments/approvals/documents/{$paymentDocument->id}/approve",
            $summary->nextAction?->href
        );
        $this->assertSame('payment_approval_required', $summary->blockers->first()?->key);
    }

    public function test_unpaid_payment_document_blocks_receipt_and_points_to_payment_registration(): void
    {
        $organization = Organization::factory()->create();
        $purchaseRequest = $this->createPurchaseRequest($organization);
        $purchaseOrder = $this->createPurchaseOrder($purchaseRequest, PurchaseOrderStatusEnum::CONFIRMED);
        $paymentDocument = $this->createPaymentDocument($purchaseOrder, PaymentDocumentStatus::APPROVED, 0.0);

        $summary = app(ProcurementChainService::class)->forPurchaseOrder($purchaseOrder);

        $this->assertSame('payment_approved', $summary->currentStage->key);
        $this->assertSame('register_payment', $summary->nextAction?->key);
        $this->assertSame('POST', $summary->nextAction?->method);
        $this->assertSame(
            "/api/v1/admin/payments/documents/{$paymentDocument->id}/register-payment",
            $summary->nextAction?->href
        );
        $this->assertSame('payment_registration_required', $summary->blockers->first()?->key);
    }

    public function test_paid_order_without_receipt_points_to_material_receipt(): void
    {
        $organization = Organization::factory()->create();
        $purchaseRequest = $this->createPurchaseRequest($organization);
        $purchaseOrder = $this->createPurchaseOrder($purchaseRequest, PurchaseOrderStatusEnum::CONFIRMED);
        $this->createPaymentDocument($purchaseOrder, PaymentDocumentStatus::PAID, 500.0);

        $summary = app(ProcurementChainService::class)->forPurchaseOrder($purchaseOrder);

        $this->assertSame('payment_registered', $summary->currentStage->key);
        $this->assertSame('receive_materials', $summary->nextAction?->key);
        $this->assertFalse($summary->compact()->isBlocked);
    }

    public function test_posted_receipt_completes_chain(): void
    {
        $organization = Organization::factory()->create();
        $purchaseRequest = $this->createPurchaseRequest($organization);
        $purchaseOrder = $this->createPurchaseOrder($purchaseRequest, PurchaseOrderStatusEnum::DELIVERED);
        $this->createPaymentDocument($purchaseOrder, PaymentDocumentStatus::PAID, 500.0);
        $receipt = $this->createReceipt($purchaseOrder);

        $summary = app(ProcurementChainService::class)->forPurchaseOrder($purchaseOrder);

        $this->assertSame('completed', $summary->currentStage->key);
        $this->assertNull($summary->nextAction);
        $this->assertFalse($summary->compact()->isBlocked);
        $this->assertSame(
            "/procurement/purchase-orders/{$purchaseOrder->id}?receipt_id={$receipt->id}",
            $summary->linkedDocuments->firstWhere('type', 'purchase_receipt')?->href
        );
    }

    public function test_permission_missing_disables_next_action_without_hiding_blocker_context(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $purchaseRequest = $this->createPurchaseRequest($organization);
        $purchaseOrder = $this->createPurchaseOrder($purchaseRequest, PurchaseOrderStatusEnum::CONFIRMED);

        $summary = app(ProcurementChainService::class)->forPurchaseOrder($purchaseOrder, $user);

        $this->assertSame('create_or_open_payment_document', $summary->nextAction?->key);
        $this->assertFalse($summary->nextAction?->isEnabled);
        $this->assertSame('payments.invoice.create', $summary->nextAction?->requiredPermission);
        $this->assertSame('payment_document_missing', $summary->blockers->first()?->key);
    }

    private function createSiteRequest(Organization $organization): SiteRequest
    {
        $project = Project::factory()->create(['organization_id' => $organization->id]);

        return SiteRequest::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => User::factory()->create(['current_organization_id' => $organization->id])->id,
            'title' => 'Материалы на площадку',
            'status' => SiteRequestStatusEnum::APPROVED,
            'priority' => 'medium',
            'request_type' => SiteRequestTypeEnum::MATERIAL_REQUEST,
            'material_name' => 'Арматура',
            'material_quantity' => 5,
            'material_unit' => 'шт',
        ]);
    }

    private function createPurchaseRequest(Organization $organization, ?SiteRequest $siteRequest = null): PurchaseRequest
    {
        $purchaseRequest = PurchaseRequest::query()->create([
            'organization_id' => $organization->id,
            'site_request_id' => $siteRequest?->id,
            'request_number' => 'PR-CHAIN-'.$organization->id.'-'.uniqid(),
            'status' => PurchaseRequestStatusEnum::APPROVED,
            'budget_currency' => 'RUB',
        ]);

        PurchaseRequestLine::query()->create([
            'purchase_request_id' => $purchaseRequest->id,
            'name' => 'Арматура',
            'quantity' => 5,
            'unit' => 'шт',
        ]);

        return $purchaseRequest;
    }

    private function createSupplierRequest(
        PurchaseRequest $purchaseRequest,
        SupplierRequestStatusEnum $status
    ): SupplierRequest {
        return SupplierRequest::query()->create([
            'organization_id' => $purchaseRequest->organization_id,
            'purchase_request_id' => $purchaseRequest->id,
            'request_number' => 'SR-CHAIN-'.$purchaseRequest->id,
            'status' => $status,
            'public_token' => 'chain-token-'.$purchaseRequest->id.str_repeat('x', 32),
            'public_token_expires_at' => now()->addDay(),
        ]);
    }

    private function createSupplierProposal(SupplierRequest $supplierRequest): SupplierProposal
    {
        return SupplierProposal::query()->create([
            'organization_id' => $supplierRequest->organization_id,
            'supplier_request_id' => $supplierRequest->id,
            'proposal_number' => 'KP-CHAIN-'.$supplierRequest->id,
            'proposal_date' => now()->toDateString(),
            'status' => SupplierProposalStatusEnum::SUBMITTED,
            'subtotal_amount' => 100,
            'delivery_amount' => 0,
            'vat_amount' => 0,
            'total_amount' => 100,
            'currency' => 'RUB',
        ]);
    }

    private function createPurchaseOrder(
        PurchaseRequest $purchaseRequest,
        PurchaseOrderStatusEnum $status
    ): PurchaseOrder {
        $purchaseOrder = PurchaseOrder::query()->create([
            'organization_id' => $purchaseRequest->organization_id,
            'purchase_request_id' => $purchaseRequest->id,
            'order_number' => 'PO-CHAIN-'.$purchaseRequest->id.'-'.uniqid(),
            'order_date' => now()->toDateString(),
            'status' => $status,
            'total_amount' => 500,
            'currency' => 'RUB',
        ]);

        PurchaseOrderItem::query()->create([
            'purchase_order_id' => $purchaseOrder->id,
            'material_name' => 'Арматура',
            'quantity' => 5,
            'unit' => 'шт',
            'unit_price' => 100,
            'total_price' => 500,
        ]);

        return $purchaseOrder;
    }

    private function createPaymentDocument(
        PurchaseOrder $purchaseOrder,
        PaymentDocumentStatus $status,
        float $paidAmount
    ): PaymentDocument {
        return PaymentDocument::query()->create([
            'organization_id' => $purchaseOrder->organization_id,
            'document_type' => PaymentDocumentType::PAYMENT_ORDER,
            'document_number' => 'PAY-CHAIN-'.$purchaseOrder->id.'-'.uniqid(),
            'document_date' => now()->toDateString(),
            'direction' => InvoiceDirection::OUTGOING,
            'invoice_type' => InvoiceType::MATERIAL_PURCHASE,
            'payer_organization_id' => $purchaseOrder->organization_id,
            'payee_organization_id' => $purchaseOrder->organization_id,
            'amount' => 500,
            'currency' => 'RUB',
            'paid_amount' => $paidAmount,
            'remaining_amount' => max(500 - $paidAmount, 0),
            'status' => $status,
            'paid_at' => $status === PaymentDocumentStatus::PAID ? now() : null,
            'metadata' => [
                'purchase_order_id' => $purchaseOrder->id,
                'purchase_order_number' => $purchaseOrder->order_number,
            ],
        ]);
    }

    private function createReceipt(PurchaseOrder $purchaseOrder): PurchaseReceipt
    {
        $warehouse = OrganizationWarehouse::query()->create([
            'organization_id' => $purchaseOrder->organization_id,
            'name' => 'Основной склад',
            'code' => 'WH-CHAIN-'.$purchaseOrder->id,
            'warehouse_type' => OrganizationWarehouse::TYPE_CENTRAL,
            'is_main' => true,
            'is_active' => true,
        ]);

        $receipt = PurchaseReceipt::query()->create([
            'organization_id' => $purchaseOrder->organization_id,
            'purchase_order_id' => $purchaseOrder->id,
            'warehouse_id' => $warehouse->id,
            'receipt_number' => 'REC-CHAIN-'.$purchaseOrder->id,
            'receipt_date' => now()->toDateString(),
            'status' => 'posted',
        ]);

        $receipt->lines()->create([
            'purchase_order_item_id' => $purchaseOrder->items()->firstOrFail()->id,
            'quantity_received' => 5,
            'price' => 100,
            'total_amount' => 500,
        ]);

        return $receipt;
    }
}
