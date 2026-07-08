<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\InvoiceType;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Features\Budgeting\Models\BudgetArticle;
use App\BusinessModules\Features\Budgeting\Models\ResponsibilityCenter;
use App\BusinessModules\Features\Procurement\Enums\PurchaseOrderStatusEnum;
use App\BusinessModules\Features\Procurement\Enums\PurchaseRequestStatusEnum;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrderItem;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequestLine;
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
        $response->assertJsonPath('data.current_stage.key', 'site_request_approved');
        $response->assertJsonPath('data.next_action.key', 'create_purchase_request');
        $response->assertJsonPath('data.linked_documents.0.type', 'site_request');
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
        $response->assertJsonPath('data.current_stage.key', 'payment_document_created');
        $response->assertJsonPath('data.next_action.key', 'register_payment');
        $response->assertJsonPath('data.next_action.href', '/payments/documents/'.$paymentDocument->id);
        $response->assertJsonPath('data.blockers.0.key', 'payment_confirmation_required');
        $this->assertContains(
            'payment_document',
            collect($response->json('data.linked_documents'))->pluck('type')->all()
        );
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
        ]);
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
            $mock->shouldReceive('can')->andReturn(true);
            $mock->shouldReceive('hasRole')->andReturn(true);
        });
    }

    private function allowPermissionsExcept(string $permission): void
    {
        $this->mock(AuthorizationService::class, function (MockInterface $mock) use ($permission): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturnUsing(
                static fn (User $user, string $checkedPermission, ?array $context = null): bool => $checkedPermission !== $permission
            );
            $mock->shouldReceive('hasRole')->andReturn(true);
        });
    }
}
