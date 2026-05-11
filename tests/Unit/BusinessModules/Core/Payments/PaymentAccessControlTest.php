<?php

namespace Tests\Unit\BusinessModules\Core\Payments;

use App\BusinessModules\Core\MultiOrganization\Contracts\OrganizationScopeInterface;
use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\InvoiceType;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Services\PaymentAccessControl;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
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

    #[Test]
    public function it_allows_access_to_own_invoice(): void
    {
        $invoice = $this->createPaymentDocument([
            'organization_id' => $this->org1->id,
        ]);

        $hasAccess = $this->accessControl->canAccessInvoice($this->org1->id, $invoice);

        $this->assertTrue($hasAccess);
    }

    #[Test]
    public function it_denies_access_to_other_organization_invoice(): void
    {
        $invoice = $this->createPaymentDocument([
            'organization_id' => $this->org1->id,
        ]);

        $hasAccess = $this->accessControl->canAccessInvoice($this->org2->id, $invoice);

        $this->assertFalse($hasAccess);
    }

    #[Test]
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

        $invoice = $this->createPaymentDocument([
            'organization_id' => $this->org1->id,
            'project_id' => $this->project->id,
        ]);

        $hasAccess = $this->accessControl->canAccessInvoice($this->org2->id, $invoice);

        $this->assertTrue($hasAccess, 'Участник проекта должен иметь доступ к счетам проекта');
    }

    #[Test]
    public function it_denies_access_to_non_project_participant(): void
    {
        $invoice = $this->createPaymentDocument([
            'organization_id' => $this->org1->id,
            'project_id' => $this->project->id,
        ]);

        $hasAccess = $this->accessControl->canAccessInvoice($this->org2->id, $invoice);

        $this->assertFalse($hasAccess, 'Не участник проекта не должен иметь доступ');
    }

    #[Test]
    public function it_allows_access_to_counterparty(): void
    {
        $invoice = $this->createPaymentDocument([
            'organization_id' => $this->org1->id,
            'counterparty_organization_id' => $this->org2->id,
        ]);

        $hasAccess = $this->accessControl->canAccessInvoice($this->org2->id, $invoice);

        $this->assertTrue($hasAccess, 'Контрагент должен видеть счета, где он указан');
    }

    #[Test]
    public function apply_access_scope_filters_correctly(): void
    {
        // Счета org1
        for ($i = 0; $i < 3; $i++) {
            $this->createPaymentDocument(['organization_id' => $this->org1->id]);
        }
        
        // Счета org2 (не должны быть доступны)
        for ($i = 0; $i < 2; $i++) {
            $this->createPaymentDocument(['organization_id' => $this->org2->id]);
        }

        $query = PaymentDocument::query();
        $query = $this->accessControl->applyAccessScope($query, $this->org1->id);
        
        $accessibleInvoices = $query->get();

        $this->assertCount(3, $accessibleInvoices);
        $this->assertTrue($accessibleInvoices->every(fn($inv) => $inv->organization_id === $this->org1->id));
    }

    #[Test]
    public function it_can_create_invoice_for_own_organization(): void
    {
        $data = [
            'organization_id' => $this->org1->id,
            'total_amount' => 10000,
        ];

        $canCreate = $this->accessControl->canCreateInvoice($this->org1->id, $data);

        $this->assertTrue($canCreate);
    }

    #[Test]
    public function it_cannot_create_invoice_for_other_organization(): void
    {
        $data = [
            'organization_id' => $this->org2->id,
            'total_amount' => 10000,
        ];

        $canCreate = $this->accessControl->canCreateInvoice($this->org1->id, $data);

        $this->assertFalse($canCreate);
    }

    private function createPaymentDocument(array $attributes = []): PaymentDocument
    {
        return PaymentDocument::create(array_merge([
            'organization_id' => $this->org1->id,
            'document_type' => 'invoice',
            'document_number' => 'PAY-' . uniqid('', true),
            'document_date' => now()->toDateString(),
            'direction' => InvoiceDirection::OUTGOING->value,
            'invoice_type' => InvoiceType::OTHER->value,
            'amount' => 10000,
            'currency' => 'RUB',
            'status' => 'draft',
        ], $attributes));
    }
}

