<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\InvoiceType;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\Budgeting\Models\BudgetArticle;
use App\BusinessModules\Features\Budgeting\Models\ResponsibilityCenter;
use App\BusinessModules\Features\Procurement\Enums\PurchaseOrderStatusEnum;
use App\BusinessModules\Features\Procurement\Enums\PurchaseRequestStatusEnum;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrderItem;
use App\BusinessModules\Features\Procurement\Models\PurchaseReceipt;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequestLine;
use App\BusinessModules\Features\Procurement\Services\ProcurementChainService;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestStatusEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestTypeEnum;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\Contract\ContractStatusEnum;
use App\Enums\Contract\ContractWorkTypeCategoryEnum;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class ProcurementChainControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_site_request_chain_endpoint_returns_detail_contract(): void
    {
        $context = AdminApiTestContext::create();
        $siteRequest = $this->createSiteRequest($context->organization);
        $this->allowModuleAccess();
        $this->allowPermissions();

        $response = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/procurement/chains/site-requests/{$siteRequest->id}");

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.root.type', 'site_request');
        $response->assertJsonPath('data.current_stage.key', 'fulfillment_source_required');
        $response->assertJsonPath('data.next_action.key', 'determine_fulfillment_source');
        $response->assertJsonPath('data.blockers.0.key', 'fulfillment_source_not_selected');
        $response->assertJsonPath('data.linked_documents.0.type', 'site_request');
        $response->assertJsonPath('data.linked_documents.0.number', 'Материалы на площадку');
        $response->assertJsonStructure([
            'data' => [
                'root',
                'current_stage',
                'next_action',
                'blockers',
                'warnings',
                'linked_documents',
                'stages',
                'permissions',
                'compact',
            ],
        ]);
    }

    public function test_purchase_order_chain_endpoint_includes_payment_document_and_receipt_state(): void
    {
        $context = AdminApiTestContext::create();
        $purchaseRequest = $this->createPurchaseRequest($context->organization);
        $purchaseOrder = $this->createPurchaseOrder($purchaseRequest, PurchaseOrderStatusEnum::CONFIRMED);
        $paymentDocument = $this->createPaymentDocument($purchaseOrder, PaymentDocumentStatus::APPROVED, 0);
        $this->allowModuleAccess();
        $this->allowPermissions();

        $response = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/procurement/chains/purchase-orders/{$purchaseOrder->id}");

        $response->assertOk();
        $response->assertJsonPath('data.current_stage.key', 'payment_approved');
        $response->assertJsonPath('data.next_action.key', 'register_payment');
        $response->assertJsonPath('data.next_action.href', "/api/v1/admin/payments/documents/{$paymentDocument->id}/register-payment");
        $response->assertJsonPath('data.next_action.method', 'POST');
        $response->assertJsonPath('data.blockers.0.key', 'payment_registration_required');
        $this->assertContains(
            'payment_document',
            collect($response->json('data.linked_documents'))->pluck('type')->all()
        );
    }

    public function test_purchase_order_detail_embeds_full_chain_while_list_keeps_compact_contract(): void
    {
        $context = AdminApiTestContext::create();
        $purchaseRequest = $this->createPurchaseRequest($context->organization);
        $purchaseOrder = $this->createPurchaseOrder($purchaseRequest, PurchaseOrderStatusEnum::CONFIRMED);
        $this->createPaymentDocument($purchaseOrder, PaymentDocumentStatus::APPROVED, 0);
        $this->allowModuleAccess();
        $this->allowPermissions();

        $detailResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/procurement/purchase-orders/{$purchaseOrder->id}");
        $listResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/procurement/purchase-orders?per_page=20');

        $detailResponse->assertOk();
        $detailResponse->assertJsonStructure([
            'data' => [
                'procurement_chain' => [
                    'current_stage',
                    'next_action',
                    'blockers',
                    'linked_documents',
                    'stages',
                ],
            ],
        ]);
        $detailResponse->assertJsonPath('data.procurement_chain.current_stage.key', 'payment_approved');
        $detailResponse->assertJsonPath('data.procurement_chain.blockers.0.key', 'payment_registration_required');
        $detailResponse->assertJsonPath('data.procurement_chain_summary.stage', 'payment_approved');

        $listResponse->assertOk();
        $this->assertArrayNotHasKey('procurement_chain', $listResponse->json('data.0'));
        $listResponse->assertJsonPath('data.0.procurement_chain_summary.stage', 'payment_approved');
    }

    public function test_purchase_order_list_reuses_receipt_parent_graphs_without_reusing_payment_reads(): void
    {
        $this->freezeTime();
        $context = AdminApiTestContext::create();
        $orderIds = [];
        $receiptIds = [];
        for ($index = 0; $index < 2; $index++) {
            $purchaseRequest = $this->createPurchaseRequest($context->organization);
            $order = $this->createPurchaseOrder($purchaseRequest, PurchaseOrderStatusEnum::PARTIALLY_DELIVERED);
            $this->createPaymentDocument($order, PaymentDocumentStatus::PAID, 500);
            $orderIds[] = $order->id;
            $receiptIds[$order->id] = [
                $this->createPostedReceipt($order, 1, 100)->id,
                $this->createPostedReceipt($order, 1, 100)->id,
            ];
        }
        $foreignRequest = $this->createPurchaseRequest(Organization::factory()->create());
        $foreignOrder = $this->createPurchaseOrder($foreignRequest, PurchaseOrderStatusEnum::PARTIALLY_DELIVERED);
        $this->createPaymentDocument($foreignOrder, PaymentDocumentStatus::PAID, 500);
        $this->createPostedReceipt($foreignOrder, 1, 100);
        $this->allowModuleAccess();
        $this->allowPermissions();
        $expectedReceiptSummaries = [];
        foreach ($receiptIds as $ids) {
            foreach ($ids as $id) {
                $expectedReceiptSummaries[$id] = app(ProcurementChainService::class)
                    ->forPurchaseReceipt(PurchaseReceipt::query()->findOrFail($id), $context->user, includePermissions: false)
                    ->compact()
                    ->toArray();
            }
        }

        DB::enableQueryLog();
        try {
            DB::flushQueryLog();
            $small = $this->withHeaders($context->authHeaders())
                ->getJson('/api/v1/admin/procurement/purchase-orders?per_page=1&sort_by=id&sort_dir=desc');
            $smallQueries = DB::getQueryLog();
            DB::flushQueryLog();
            $large = $this->withHeaders($context->authHeaders())
                ->getJson('/api/v1/admin/procurement/purchase-orders?per_page=2&sort_by=id&sort_dir=desc');
            $largeQueries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $small->assertOk();
        $large->assertOk();
        $this->assertSame(array_reverse($orderIds), collect($large->json('data'))->pluck('id')->all());
        $this->assertSame($small->json('data.0'), $large->json('data.0'));
        foreach ($large->json('data') as $payload) {
            $this->assertSame((int) $context->organization->id, $payload['organization_id']);
            $this->assertArrayNotHasKey('procurement_chain', $payload);
            $this->assertEquals(500, $payload['payment_summary']['paid_amount']);
            $this->assertEquals(200, $payload['payment_summary']['received_amount']);
            $this->assertEquals(300, $payload['payment_summary']['available_paid_amount']);
            $this->assertSame(1, $payload['payment_summary']['documents_count']);
            $this->assertSame('partially_delivered', $payload['procurement_chain_summary']['stage']);
            $actualReceiptIds = array_column($payload['receipts'], 'id');
            $this->assertEqualsCanonicalizing($receiptIds[$payload['id']], $actualReceiptIds);
            foreach ($payload['receipts'] as $receipt) {
                $this->assertSame($payload['id'], $receipt['purchase_order_id']);
                $this->assertSame($payload['organization_id'], $receipt['organization_id']);
                $this->assertArrayNotHasKey('purchase_order', $receipt);
                $this->assertSame($expectedReceiptSummaries[$receipt['id']], $receipt['procurement_chain_summary']);
                $this->assertCount(1, $receipt['lines']);
            }
        }

        $linkedPaymentReadCount = static fn (array $queries): int => count(array_filter(
            $queries,
            static fn (array $query): bool => str_contains($query['query'], 'from "payment_documents"')
                && str_contains($query['query'], 'order by "document_date" desc'),
        ));
        $this->assertSame(3, $linkedPaymentReadCount($smallQueries));
        $this->assertSame(6, $linkedPaymentReadCount($largeQueries));
        $graphQueryCount = static fn (array $queries): int => count(array_filter(
            $queries,
            static fn (array $query): bool => preg_match('/\bsum\s*\(/i', $query['query']) !== 1
                && preg_match('/\b(?:from|join)\s+"?(?:purchase_requests|purchase_orders|supplier_proposals|supplier_proposal_decisions|purchase_order_items|purchase_receipts|purchase_receipt_lines|organization_warehouses|material_deliveries|site_requests)\b/i', $query['query']) === 1,
        ));
        $this->assertGreaterThan(0, $graphQueryCount($smallQueries));
        $this->assertLessThanOrEqual($graphQueryCount($smallQueries) + 1, $graphQueryCount($largeQueries));
    }

    public function test_purchase_order_list_preserves_an_explicitly_loaded_null_receipt_parent(): void
    {
        $this->freezeTime();
        $context = AdminApiTestContext::create();
        $purchaseRequest = $this->createPurchaseRequest($context->organization);
        $order = $this->createPurchaseOrder($purchaseRequest, PurchaseOrderStatusEnum::PARTIALLY_DELIVERED);
        $this->createPaymentDocument($order, PaymentDocumentStatus::PAID, 500);
        $receipt = $this->createPostedReceipt($order, 1, 100);
        $this->allowModuleAccess();
        $this->allowPermissions();
        $receipt->setRelation('purchaseOrder', null);
        $expected = app(ProcurementChainService::class)
            ->forPurchaseReceipt($receipt, $context->user, includePermissions: false)
            ->compact()
            ->toArray();
        $dispatcher = PurchaseReceipt::getEventDispatcher();
        $this->assertNotNull($dispatcher);
        PurchaseReceipt::setEventDispatcher(clone $dispatcher);
        try {
            PurchaseReceipt::retrieved(static function (PurchaseReceipt $loadedReceipt): void {
                $loadedReceipt->setRelation('purchaseOrder', null);
            });
            $response = $this->withHeaders($context->authHeaders())
                ->getJson('/api/v1/admin/procurement/purchase-orders');
        } finally {
            PurchaseReceipt::setEventDispatcher($dispatcher);
        }

        $response->assertOk();
        $response->assertJsonCount(1, 'data.0.receipts');
        $response->assertJsonPath('data.0.receipts.0.id', $receipt->id);
        $this->assertSame($expected, $response->json('data.0.receipts.0.procurement_chain_summary'));
        $this->assertSame($dispatcher, PurchaseReceipt::getEventDispatcher());
    }

    public function test_partially_delivered_order_chain_uses_receipt_and_payment_state(): void
    {
        $context = AdminApiTestContext::create();
        $purchaseRequest = $this->createPurchaseRequest($context->organization);
        $purchaseOrder = $this->createPurchaseOrder($purchaseRequest, PurchaseOrderStatusEnum::PARTIALLY_DELIVERED);
        $this->createPaymentDocument($purchaseOrder, PaymentDocumentStatus::PAID, 500);
        $this->createPostedReceipt($purchaseOrder, 2, 100);
        $this->allowModuleAccess();
        $this->allowPermissions();

        $response = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/procurement/purchase-orders/{$purchaseOrder->id}");

        $response->assertOk();
        $response->assertJsonPath('data.status', PurchaseOrderStatusEnum::PARTIALLY_DELIVERED->value);
        $response->assertJsonPath('data.procurement_chain.current_stage.key', 'partially_delivered');
        $response->assertJsonPath('data.procurement_chain.next_action.key', 'receive_materials');
        $response->assertJsonPath('data.procurement_chain.blockers', []);
    }

    public function test_partially_delivered_order_chain_keeps_payment_blocker_when_remaining_payment_is_insufficient(): void
    {
        $context = AdminApiTestContext::create();
        $purchaseRequest = $this->createPurchaseRequest($context->organization);
        $purchaseOrder = $this->createPurchaseOrder($purchaseRequest, PurchaseOrderStatusEnum::PARTIALLY_DELIVERED);
        $this->createPaymentDocument($purchaseOrder, PaymentDocumentStatus::PAID, 200);
        $this->createPostedReceipt($purchaseOrder, 2, 100);
        $this->allowModuleAccess();
        $this->allowPermissions();

        $response = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/procurement/purchase-orders/{$purchaseOrder->id}");

        $response->assertOk();
        $response->assertJsonPath('data.procurement_chain.current_stage.key', 'payment_partially_registered');
        $response->assertJsonPath('data.procurement_chain.blockers.0.key', 'payment_amount_not_enough');
        $response->assertJsonPath('data.procurement_chain.next_action.key', 'register_payment');
    }

    public function test_completed_order_chain_uses_completed_labels_for_conditional_stages(): void
    {
        $context = AdminApiTestContext::create();
        $purchaseRequest = $this->createPurchaseRequest($context->organization);
        $purchaseOrder = $this->createPurchaseOrder($purchaseRequest, PurchaseOrderStatusEnum::DELIVERED);
        $this->createPaymentDocument($purchaseOrder, PaymentDocumentStatus::PAID, 500);
        $this->createPostedReceipt($purchaseOrder, 5, 100);
        $this->allowModuleAccess();
        $this->allowPermissions();

        $response = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/procurement/purchase-orders/{$purchaseOrder->id}");

        $response->assertOk();
        $response->assertJsonPath('data.procurement_chain.current_stage.key', 'completed');

        $stages = collect($response->json('data.procurement_chain.stages'))->keyBy('key');
        $expectedLabels = [
            'payment_document_missing' => 'Платежный документ создан',
            'payment_document_draft' => 'Счет подготовлен',
            'payment_approval_required' => 'Счет передан на согласование',
            'payment_partially_registered' => 'Оплата начата',
            'partially_delivered' => 'Поставка принята полностью',
        ];

        foreach ($expectedLabels as $stageKey => $expectedLabel) {
            $this->assertSame('done', $stages->get($stageKey)['status']);
            $this->assertSame($expectedLabel, $stages->get($stageKey)['label']);
        }
    }

    public function test_payment_document_endpoint_is_idempotent_and_uses_supplier_contractor(): void
    {
        $context = AdminApiTestContext::create();
        $purchaseRequest = $this->createPurchaseRequest($context->organization);
        $purchaseOrder = $this->createPurchaseOrder($purchaseRequest, PurchaseOrderStatusEnum::CONFIRMED, [
            'supplier_snapshot' => [
                'display_name' => 'Поставщик цепочки',
                'tax_id' => '7700000000',
                'email' => 'supplier-chain@example.test',
            ],
        ]);
        $this->allowModuleAccess();
        $this->allowPermissions();

        $firstResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/procurement/purchase-orders/{$purchaseOrder->id}/payment-document");
        $secondResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/procurement/purchase-orders/{$purchaseOrder->id}/payment-document");

        $firstResponse->assertCreated();
        $secondResponse->assertOk();
        $this->assertSame($firstResponse->json('data.id'), $secondResponse->json('data.id'));
        $this->assertSame($firstResponse->json('data.id'), $firstResponse->json('data.payment_document.id'));
        $this->assertSame('submit_payment_document', $firstResponse->json('data.payment_action_summary.primary_action.key'));
        $this->assertSame('payment_document_draft', $firstResponse->json('data.procurement_chain_summary.current_stage.key'));
        $this->assertDatabaseHas('contractors', [
            'organization_id' => $context->organization->id,
            'name' => 'Поставщик цепочки',
            'inn' => '7700000000',
        ]);
        $this->assertDatabaseHas('payment_documents', [
            'id' => $firstResponse->json('data.id'),
            'organization_id' => $context->organization->id,
            'payer_organization_id' => $context->organization->id,
            'payee_contractor_id' => Contractor::query()->where('inn', '7700000000')->value('id'),
        ]);
        $this->assertSame(
            now()->toDateString(),
            PaymentDocument::query()->findOrFail($firstResponse->json('data.id'))->document_date?->toDateString()
        );
    }

    public function test_payment_document_endpoint_can_submit_after_create_when_requested(): void
    {
        $context = AdminApiTestContext::create();
        $purchaseRequest = $this->createPurchaseRequest($context->organization);
        $purchaseOrder = $this->createPurchaseOrder($purchaseRequest, PurchaseOrderStatusEnum::CONFIRMED, [
            'supplier_snapshot' => [
                'display_name' => 'Submit Supplier',
                'tax_id' => '7700000003',
                'email' => 'submit-supplier@example.test',
            ],
        ]);
        $this->allowModuleAccess();
        $this->allowPermissions();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/procurement/purchase-orders/{$purchaseOrder->id}/payment-document", [
                'submit_after_create' => true,
                'bank_account' => '40702810900000000001',
                'bank_bik' => '044525001',
                'bank_correspondent_account' => '30101810400000000225',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.submitted', true);
        $response->assertJsonPath('data.payment_action_summary.primary_action.key', 'approve_payment_document');
        $response->assertJsonPath('data.procurement_chain_summary.current_stage.key', 'payment_approval_required');
        $this->assertDatabaseHas('payment_approvals', [
            'payment_document_id' => $response->json('data.id'),
            'approval_role' => null,
            'approval_permission' => 'payments.transaction.approve',
            'status' => 'pending',
        ]);
    }

    public function test_purchase_order_payment_document_uses_material_purchase_limit_for_procurement_contract(): void
    {
        $context = AdminApiTestContext::create();
        $purchaseRequest = $this->createPurchaseRequest($context->organization);
        $contractor = Contractor::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Concrete Supplier',
            'email' => 'concrete-supplier@example.test',
            'inn' => '7700000001',
            'contractor_type' => Contractor::TYPE_MANUAL,
        ]);
        $contract = Contract::query()->create([
            'organization_id' => $context->organization->id,
            'contractor_id' => $contractor->id,
            'contract_category' => 'procurement',
            'number' => 'SUP-CHAIN-'.uniqid(),
            'currency' => 'USD',
            'date' => now()->toDateString(),
            'subject' => 'Concrete supply',
            'work_type_category' => ContractWorkTypeCategoryEnum::SUPPLY,
            'base_amount' => 500,
            'total_amount' => 500,
            'status' => ContractStatusEnum::DRAFT,
            'is_fixed_amount' => true,
        ]);
        $purchaseOrder = $this->createPurchaseOrder($purchaseRequest, PurchaseOrderStatusEnum::CONFIRMED, [
            'contract_id' => $contract->id,
            'total_amount' => 500,
            'currency' => '',
            'supplier_snapshot' => [
                'display_name' => 'Concrete Supplier',
                'tax_id' => '7700000001',
                'email' => 'concrete-supplier@example.test',
            ],
        ]);
        $this->allowModuleAccess();
        $this->allowPermissions();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/procurement/purchase-orders/{$purchaseOrder->id}/payment-document");

        $response->assertCreated();
        $this->assertDatabaseHas('payment_documents', [
            'id' => $response->json('data.id'),
            'organization_id' => $context->organization->id,
            'source_type' => Contract::class,
            'source_id' => $contract->id,
            'invoice_type' => InvoiceType::MATERIAL_PURCHASE->value,
            'amount' => 500,
            'currency' => 'USD',
        ]);
    }

    public function test_contract_payment_availability_uses_posted_procurement_receipts_instead_of_acts(): void
    {
        $context = AdminApiTestContext::create();
        $purchaseRequest = $this->createPurchaseRequest($context->organization);
        $contractor = Contractor::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Availability supplier',
            'inn' => '7700000002',
            'contractor_type' => Contractor::TYPE_MANUAL,
        ]);
        $contract = Contract::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $purchaseRequest->project_id,
            'contractor_id' => $contractor->id,
            'contract_category' => 'procurement',
            'number' => 'SUP-AVAILABILITY-'.uniqid(),
            'date' => now()->toDateString(),
            'subject' => 'Material supply',
            'work_type_category' => ContractWorkTypeCategoryEnum::SUPPLY,
            'base_amount' => 5715,
            'total_amount' => 5715,
            'status' => ContractStatusEnum::ACTIVE,
            'is_fixed_amount' => true,
        ]);
        $purchaseOrder = $this->createPurchaseOrder($purchaseRequest, PurchaseOrderStatusEnum::CONFIRMED, [
            'contract_id' => $contract->id,
            'total_amount' => 5715,
        ]);
        $this->createPostedReceipt($purchaseOrder, 5, 360);
        DB::table('contract_performance_acts')->insert([
            'contract_id' => $contract->id,
            'project_id' => $purchaseRequest->project_id,
            'act_document_number' => 'KS-2-AVAILABILITY',
            'act_date' => now()->toDateString(),
            'amount' => 5715,
            'status' => 'approved',
            'is_approved' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->allowModuleAccess();
        $this->allowPermissions();

        $response = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/payments/contracts/{$contract->id}/availability");

        $response->assertOk();
        $response->assertJsonPath('data.contract_id', $contract->id);
        $response->assertJsonPath('data.basis', 'procurement_receipts');
        $response->assertJsonPath('data.performed_amount', 1800);
        $response->assertJsonPath('data.committed_amount', 0);
        $response->assertJsonPath('data.available_amount', 1800);
    }

    public function test_payment_document_endpoint_applies_budget_dimensions_from_request(): void
    {
        $context = AdminApiTestContext::create();
        $purchaseRequest = $this->createPurchaseRequest($context->organization);
        $purchaseOrder = $this->createPurchaseOrder($purchaseRequest, PurchaseOrderStatusEnum::CONFIRMED, [
            'supplier_snapshot' => [
                'display_name' => 'Budget Supplier',
                'tax_id' => '7700000002',
                'email' => 'budget-supplier@example.test',
            ],
        ]);
        $article = BudgetArticle::query()->create([
            'organization_id' => $context->organization->id,
            'code' => 'MAT-'.uniqid(),
            'name' => 'Материалы',
            'budget_kind' => 'bdds',
            'flow_direction' => 'outflow',
            'is_leaf' => true,
            'is_active' => true,
        ]);
        $center = ResponsibilityCenter::query()->create([
            'organization_id' => $context->organization->id,
            'center_type' => 'project',
            'code' => 'CFO-'.uniqid(),
            'name' => 'Производство',
            'is_active' => true,
        ]);
        $this->allowModuleAccess();
        $this->allowPermissions();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/procurement/purchase-orders/{$purchaseOrder->id}/payment-document", [
                'budget_article_id' => $article->uuid,
                'responsibility_center_id' => $center->uuid,
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('payment_documents', [
            'id' => $response->json('data.id'),
            'organization_id' => $context->organization->id,
            'budget_article_id' => $article->id,
            'responsibility_center_id' => $center->id,
        ]);
    }

    public function test_missing_payment_permission_disables_payment_action_in_chain(): void
    {
        $context = AdminApiTestContext::create();
        $purchaseRequest = $this->createPurchaseRequest($context->organization);
        $purchaseOrder = $this->createPurchaseOrder($purchaseRequest, PurchaseOrderStatusEnum::CONFIRMED);
        $this->allowModuleAccess();
        $this->allowPermissionsExcept('payments.invoice.create');

        $response = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/procurement/chains/purchase-orders/{$purchaseOrder->id}");

        $response->assertOk();
        $response->assertJsonPath('data.next_action.key', 'create_or_open_payment_document');
        $response->assertJsonPath('data.next_action.is_enabled', false);
        $response->assertJsonPath('data.next_action.required_permission', 'payments.invoice.create');
        $response->assertJsonPath('data.blockers.0.key', 'payment_document_missing');
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
            'request_number' => 'PR-CHAIN-API-'.$organization->id.'-'.uniqid(),
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

    private function createPurchaseOrder(
        PurchaseRequest $purchaseRequest,
        PurchaseOrderStatusEnum $status,
        array $overrides = []
    ): PurchaseOrder {
        $purchaseOrder = PurchaseOrder::query()->create(array_merge([
            'organization_id' => $purchaseRequest->organization_id,
            'purchase_request_id' => $purchaseRequest->id,
            'order_number' => 'PO-CHAIN-API-'.$purchaseRequest->id.'-'.uniqid(),
            'order_date' => now()->toDateString(),
            'status' => $status,
            'total_amount' => 500,
            'currency' => 'RUB',
        ], $overrides));

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
            'document_number' => 'PAY-CHAIN-API-'.$purchaseOrder->id.'-'.uniqid(),
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
            'metadata' => [
                'purchase_order_id' => $purchaseOrder->id,
                'purchase_order_number' => $purchaseOrder->order_number,
            ],
        ]);
    }

    private function createPostedReceipt(PurchaseOrder $purchaseOrder, float $quantity, float $price): PurchaseReceipt
    {
        $warehouse = OrganizationWarehouse::query()->create([
            'organization_id' => $purchaseOrder->organization_id,
            'name' => 'Склад цепочки',
            'code' => 'CHAIN-'.uniqid(),
            'warehouse_type' => OrganizationWarehouse::TYPE_CENTRAL,
            'is_main' => true,
            'is_active' => true,
        ]);
        $receipt = PurchaseReceipt::query()->create([
            'organization_id' => $purchaseOrder->organization_id,
            'purchase_order_id' => $purchaseOrder->id,
            'warehouse_id' => $warehouse->id,
            'receipt_number' => 'REC-CHAIN-'.uniqid(),
            'receipt_date' => now()->toDateString(),
            'status' => 'posted',
        ]);

        $receipt->lines()->create([
            'purchase_order_item_id' => $purchaseOrder->items()->value('id'),
            'quantity_received' => $quantity,
            'price' => $price,
            'total_amount' => $quantity * $price,
        ]);

        return $receipt;
    }

    private function allowModuleAccess(): void
    {
        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')->andReturn(true);
        });
    }

    private function allowPermissions(): void
    {
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can', 'canCurrent')->andReturn(true);
            $mock->shouldReceive('hasRole')->andReturn(true);
        });
    }

    private function allowPermissionsExcept(string $permission): void
    {
        $this->mock(AuthorizationService::class, function (MockInterface $mock) use ($permission): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can', 'canCurrent')->andReturnUsing(
                static fn (User $user, string $checkedPermission, ?array $context = null): bool => $checkedPermission !== $permission
            );
            $mock->shouldReceive('hasRole')->andReturn(true);
        });
    }
}
