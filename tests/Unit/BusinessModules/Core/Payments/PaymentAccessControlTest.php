<?php

namespace Tests\Unit\BusinessModules\Core\Payments;

use App\BusinessModules\Core\MultiOrganization\Contracts\OrganizationScopeInterface;
use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\InvoiceStatus;
use App\BusinessModules\Core\Payments\Enums\InvoiceType;
use App\BusinessModules\Core\Payments\Models\Invoice;
use App\BusinessModules\Core\Payments\Services\PaymentAccessControl;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PaymentAccessControlTest extends TestCase
{
    use RefreshDatabase;

    private PaymentAccessControl $accessControl;
    private Organization $org1;
    private Organization $org2;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $orgScope = $this->createMock(OrganizationScopeInterface::class);
        $orgScope->method('getOrganizationScope')->willReturnCallback(fn($id) => [$id]);
        
        $this->accessControl = new PaymentAccessControl($orgScope);

        // Создать тестовые организации
        $this->org1 = Organization::factory()->create(['name' => 'Org 1']);
        $this->org2 = Organization::factory()->create(['name' => 'Org 2']);
        
        // Создать проект
        $this->project = Project::factory()->create([
            'organization_id' => $this->org1->id,
            'name' => 'Test Project',
        ]);
    }

    /** @test */
    public function it_allows_access_to_own_invoice(): void
    {
        $invoice = Invoice::factory()->create([
            'organization_id' => $this->org1->id,
        ]);

        $hasAccess = $this->accessControl->canAccessInvoice($this->org1->id, $invoice);

        $this->assertTrue($hasAccess);
    }

    /** @test */
    public function it_denies_access_to_other_organization_invoice(): void
    {
        $invoice = Invoice::factory()->create([
            'organization_id' => $this->org1->id,
        ]);

        $hasAccess = $this->accessControl->canAccessInvoice($this->org2->id, $invoice);

        $this->assertFalse($hasAccess);
    }

    /** @test */
    public function it_allows_access_to_project_participant(): void
    {
        // Добавить org2 как участника проекта
        DB::table('project_organization')->insert([
            'project_id' => $this->project->id,
            'organization_id' => $this->org2->id,
            'role' => 'contractor',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $invoice = Invoice::factory()->create([
            'organization_id' => $this->org1->id,
            'project_id' => $this->project->id,
        ]);

        $hasAccess = $this->accessControl->canAccessInvoice($this->org2->id, $invoice);

        $this->assertTrue($hasAccess, 'Участник проекта должен иметь доступ к счетам проекта');
    }

    /** @test */
    public function it_denies_access_to_non_project_participant(): void
    {
        $invoice = Invoice::factory()->create([
            'organization_id' => $this->org1->id,
            'project_id' => $this->project->id,
        ]);

        $hasAccess = $this->accessControl->canAccessInvoice($this->org2->id, $invoice);

        $this->assertFalse($hasAccess, 'Не участник проекта не должен иметь доступ');
    }

    /** @test */
    public function it_allows_access_to_counterparty(): void
    {
        $invoice = Invoice::factory()->create([
            'organization_id' => $this->org1->id,
            'counterparty_organization_id' => $this->org2->id,
        ]);

        $hasAccess = $this->accessControl->canAccessInvoice($this->org2->id, $invoice);

        $this->assertTrue($hasAccess, 'Контрагент должен видеть счета, где он указан');
    }

    /** @test */
    public function apply_access_scope_filters_correctly(): void
    {
        // Счета org1
        Invoice::factory()->count(3)->create(['organization_id' => $this->org1->id]);
        
        // Счета org2 (не должны быть доступны)
        Invoice::factory()->count(2)->create(['organization_id' => $this->org2->id]);

        $query = Invoice::query();
        $query = $this->accessControl->applyAccessScope($query, $this->org1->id);
        
        $accessibleInvoices = $query->get();

        $this->assertCount(3, $accessibleInvoices);
        $this->assertTrue($accessibleInvoices->every(fn($inv) => $inv->organization_id === $this->org1->id));
    }

    /** @test */
    public function it_can_create_invoice_for_own_organization(): void
    {
        $data = [
            'organization_id' => $this->org1->id,
            'total_amount' => 10000,
        ];

        $canCreate = $this->accessControl->canCreateInvoice($this->org1->id, $data);

        $this->assertTrue($canCreate);
    }

    /** @test */
    public function it_cannot_create_invoice_for_other_organization(): void
    {
        $data = [
            'organization_id' => $this->org2->id,
            'total_amount' => 10000,
        ];

        $canCreate = $this->accessControl->canCreateInvoice($this->org1->id, $data);

        $this->assertFalse($canCreate);
    }
}

