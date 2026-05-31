<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\Procurement\Enums\PurchaseOrderStatusEnum;
use App\BusinessModules\Features\Procurement\Enums\PurchaseRequestStatusEnum;
use App\BusinessModules\Features\Procurement\Models\ExternalSupplierContact;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\Contract\ContractStatusEnum;
use App\Enums\Contract\ContractWorkTypeCategoryEnum;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\User;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class ProcurementContractCoreExperienceControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_list_show_and_create_contract_from_purchase_order_without_organization_leaks(): void
    {
        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $supplier = $this->createSupplier($context->organization->id, 'Own Supplier');
        $foreignContract = $this->createContract($foreignContext->organization->id);
        $workContract = Contract::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'number' => 'WORK-001',
            'date' => now()->toDateString(),
            'subject' => 'Work contract',
            'work_type_category' => ContractWorkTypeCategoryEnum::SMR,
            'total_amount' => 1000,
            'status' => ContractStatusEnum::DRAFT,
        ]);
        $purchaseOrder = $this->createPurchaseOrder($context->organization->id, $supplier->id);
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $createResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/procurement/contracts', [
                'supplier_id' => $supplier->id,
                'project_id' => $project->id,
                'number' => 'SUP-001',
                'date' => now()->toDateString(),
                'subject' => 'Supply of construction materials',
                'total_amount' => 250000,
                'start_date' => now()->toDateString(),
                'end_date' => now()->addMonth()->toDateString(),
                'notes' => 'Manual procurement contract',
            ]);

        $createResponse->assertCreated();
        $createResponse->assertJsonPath('success', true);
        $createResponse->assertJsonPath('data.supplier_id', $supplier->id);
        $createResponse->assertJsonPath('data.project_id', $project->id);
        $createResponse->assertJsonPath('data.contract_category', 'procurement');
        $createResponse->assertJsonPath('data.work_type_category', ContractWorkTypeCategoryEnum::SUPPLY->value);
        $createResponse->assertJsonPath('data.status', ContractStatusEnum::DRAFT->value);

        $contract = Contract::query()->findOrFail($createResponse->json('data.id'));
        $this->assertSame($context->organization->id, $contract->organization_id);
        $this->assertSame($supplier->id, $contract->supplier_id);
        $this->assertSame($project->id, $contract->project_id);

        $indexResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/procurement/contracts?per_page=20&supplier_id={$supplier->id}");

        $indexResponse->assertOk();
        $ids = collect($indexResponse->json('data'))->pluck('id')->all();
        $this->assertContains($contract->id, $ids);
        $this->assertNotContains($foreignContract->id, $ids);
        $this->assertNotContains($workContract->id, $ids);

        $showResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/procurement/contracts/{$contract->id}");

        $showResponse->assertOk();
        $showResponse->assertJsonPath('data.id', $contract->id);
        $showResponse->assertJsonPath('data.supplier.id', $supplier->id);

        $foreignShowResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/procurement/contracts/{$foreignContract->id}");

        $foreignShowResponse->assertNotFound();

        $fromOrderResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/procurement/purchase-orders/{$purchaseOrder->id}/create-contract");

        $fromOrderResponse->assertCreated();
        $fromOrderResponse->assertJsonPath('data.purchase_order.id', $purchaseOrder->id);
        $fromOrderResponse->assertJsonPath('data.contract.supplier_id', $supplier->id);

        $purchaseOrder->refresh();
        $this->assertNotNull($purchaseOrder->contract_id);
        $this->assertDatabaseHas('contracts', [
            'id' => $purchaseOrder->contract_id,
            'organization_id' => $context->organization->id,
            'supplier_id' => $supplier->id,
            'contract_category' => 'procurement',
        ]);

        $duplicateContractResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/procurement/purchase-orders/{$purchaseOrder->id}/create-contract");

        $duplicateContractResponse->assertStatus(422);

        $foreignOrder = $this->createPurchaseOrder($foreignContext->organization->id, $this->createSupplier($foreignContext->organization->id, 'Foreign Supplier')->id);
        $foreignOrderResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/procurement/purchase-orders/{$foreignOrder->id}/create-contract");

        $foreignOrderResponse->assertNotFound();
    }

    public function test_procurement_contract_creation_rejects_foreign_supplier_and_project_without_mutation(): void
    {
        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $ownSupplier = $this->createSupplier($context->organization->id, 'Own Supplier');
        $foreignSupplier = $this->createSupplier($foreignContext->organization->id, 'Foreign Supplier');
        $foreignProject = Project::factory()->create(['organization_id' => $foreignContext->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $foreignSupplierResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/procurement/contracts', [
                'supplier_id' => $foreignSupplier->id,
                'date' => now()->toDateString(),
                'subject' => 'Foreign supplier contract',
                'total_amount' => 1000,
            ]);

        $foreignSupplierResponse->assertStatus(422);

        $foreignProjectResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/procurement/contracts', [
                'supplier_id' => $ownSupplier->id,
                'project_id' => $foreignProject->id,
                'date' => now()->toDateString(),
                'subject' => 'Foreign project contract',
                'total_amount' => 1000,
            ]);

        $foreignProjectResponse->assertStatus(422);

        $this->assertSame(0, Contract::query()
            ->where('organization_id', $context->organization->id)
            ->where('contract_category', 'procurement')
            ->count());
    }

    public function test_external_supplier_contract_from_order_is_visible_in_registry_and_detail(): void
    {
        $context = AdminApiTestContext::create();
        $purchaseOrder = $this->createExternalPurchaseOrder($context->organization->id);
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $createResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/procurement/purchase-orders/{$purchaseOrder->id}/create-contract");

        $createResponse->assertCreated();
        $createResponse->assertJsonPath('data.contract.supplier_id', null);
        $createResponse->assertJsonPath('data.contract.supplier_display_name', 'External Concrete Supplier');
        $createResponse->assertJsonPath('data.contract.contractor.name', 'External Concrete Supplier');

        $contractId = (int) $createResponse->json('data.contract.id');

        $indexResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/procurement/contracts?per_page=20');

        $indexResponse->assertOk();
        $registryContract = collect($indexResponse->json('data'))
            ->firstWhere('id', $contractId);

        $this->assertIsArray($registryContract);
        $this->assertSame('External Concrete Supplier', $registryContract['supplier_display_name'] ?? null);
        $this->assertSame('External Concrete Supplier', $registryContract['contractor']['name'] ?? null);

        $showResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/procurement/contracts/{$contractId}");

        $showResponse->assertOk();
        $showResponse->assertJsonPath('data.supplier_display_name', 'External Concrete Supplier');
        $showResponse->assertJsonPath('data.contractor.name', 'External Concrete Supplier');

        $this->assertDatabaseHas('contracts', [
            'id' => $contractId,
            'organization_id' => $context->organization->id,
            'supplier_id' => null,
            'contractor_id' => $registryContract['contractor_id'],
            'contract_category' => 'procurement',
        ]);
        $this->assertDatabaseHas('contractors', [
            'id' => $registryContract['contractor_id'],
            'organization_id' => $context->organization->id,
            'name' => 'External Concrete Supplier',
            'inn' => '7712345678',
            'contractor_type' => Contractor::TYPE_MANUAL,
        ]);
    }

    public function test_procurement_contract_creation_requires_contract_create_permission(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $supplier = $this->createSupplier($context->organization->id, 'Restricted Supplier');
        $purchaseOrder = $this->createPurchaseOrder($context->organization->id, $supplier->id);
        $this->allowAdminAccessWithoutContractCreation();
        $this->allowModuleAccess();

        $manualCreateResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/procurement/contracts', [
                'supplier_id' => $supplier->id,
                'project_id' => $project->id,
                'date' => now()->toDateString(),
                'subject' => 'Restricted manual procurement contract',
                'total_amount' => 1000,
            ]);

        $manualCreateResponse->assertForbidden();

        $fromOrderResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/procurement/purchase-orders/{$purchaseOrder->id}/create-contract");

        $fromOrderResponse->assertForbidden();
        $this->assertNull($purchaseOrder->fresh()->contract_id);
        $this->assertSame(0, Contract::query()
            ->where('organization_id', $context->organization->id)
            ->where('contract_category', 'procurement')
            ->count());
    }

    public function test_procurement_contract_creation_requires_basic_warehouse_module_without_mutation(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $supplier = $this->createSupplier($context->organization->id, 'Warehouse Required Supplier');
        $purchaseOrder = $this->createPurchaseOrder($context->organization->id, $supplier->id);
        $this->allowAdminAccess();
        $this->allowModuleAccessWithoutWarehouse();

        $manualCreateResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/procurement/contracts', [
                'supplier_id' => $supplier->id,
                'project_id' => $project->id,
                'date' => now()->toDateString(),
                'subject' => 'Supply contract without warehouse module',
                'total_amount' => 1000,
            ]);

        $manualCreateResponse->assertForbidden();

        $fromOrderResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/procurement/purchase-orders/{$purchaseOrder->id}/create-contract");

        $fromOrderResponse->assertForbidden();
        $this->assertNull($purchaseOrder->fresh()->contract_id);
        $this->assertSame(0, Contract::query()
            ->where('organization_id', $context->organization->id)
            ->where('contract_category', 'procurement')
            ->count());
    }

    private function createPurchaseOrder(int $organizationId, int $supplierId): PurchaseOrder
    {
        $purchaseRequest = PurchaseRequest::query()->create([
            'organization_id' => $organizationId,
            'request_number' => 'PR-CONTRACT-'.uniqid(),
            'status' => PurchaseRequestStatusEnum::APPROVED,
        ]);

        return PurchaseOrder::query()->create([
            'organization_id' => $organizationId,
            'purchase_request_id' => $purchaseRequest->id,
            'supplier_id' => $supplierId,
            'order_number' => 'PO-CONTRACT-'.uniqid(),
            'order_date' => now()->toDateString(),
            'status' => PurchaseOrderStatusEnum::CONFIRMED,
            'total_amount' => 50000,
            'currency' => 'RUB',
        ]);
    }

    private function createExternalPurchaseOrder(int $organizationId): PurchaseOrder
    {
        $purchaseRequest = PurchaseRequest::query()->create([
            'organization_id' => $organizationId,
            'request_number' => 'PR-EXTERNAL-CONTRACT-'.uniqid(),
            'status' => PurchaseRequestStatusEnum::APPROVED,
        ]);

        $contact = ExternalSupplierContact::query()->create([
            'organization_id' => $organizationId,
            'name' => 'External Concrete Supplier',
            'contact_person' => 'External Manager',
            'phone' => '+7 900 111-22-33',
            'email' => 'external-concrete@example.test',
            'tax_number' => '7712345678',
            'address' => 'Industrial street 1',
        ]);

        return PurchaseOrder::query()->create([
            'organization_id' => $organizationId,
            'purchase_request_id' => $purchaseRequest->id,
            'supplier_id' => null,
            'external_supplier_contact_id' => $contact->id,
            'supplier_snapshot' => [
                'type' => 'external',
                'display_name' => 'External Concrete Supplier',
                'email' => 'external-concrete@example.test',
                'phone' => '+7 900 111-22-33',
                'tax_id' => '7712345678',
            ],
            'order_number' => 'PO-EXTERNAL-CONTRACT-'.uniqid(),
            'order_date' => now()->toDateString(),
            'status' => PurchaseOrderStatusEnum::CONFIRMED,
            'total_amount' => 50000,
            'currency' => 'RUB',
        ]);
    }

    private function createContract(int $organizationId): Contract
    {
        $supplier = $this->createSupplier($organizationId, 'Contract Supplier');

        return Contract::query()->create([
            'organization_id' => $organizationId,
            'supplier_id' => $supplier->id,
            'contract_category' => 'procurement',
            'number' => 'SUP-FOR-'.uniqid(),
            'date' => now()->toDateString(),
            'subject' => 'Foreign procurement contract',
            'work_type_category' => ContractWorkTypeCategoryEnum::SUPPLY,
            'total_amount' => 5000,
            'status' => ContractStatusEnum::DRAFT,
        ]);
    }

    private function createSupplier(int $organizationId, string $name): Supplier
    {
        return Supplier::query()->create([
            'organization_id' => $organizationId,
            'name' => $name,
            'email' => strtolower(str_replace(' ', '-', $name)).'-'.uniqid().'@example.test',
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

    private function allowModuleAccessWithoutWarehouse(): void
    {
        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')
                ->andReturnUsing(static fn (int $organizationId, string $moduleSlug): bool => $moduleSlug === 'procurement');
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

    private function allowAdminAccessWithoutContractCreation(): void
    {
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturnUsing(
                static fn (User $user, string $permission): bool => $permission !== 'procurement.contracts.create'
            );
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
