<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessModules\Core\Payments;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModuleActivation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class CounterpartyAccountControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_finance_admin_can_list_counterparty_accounts_without_cross_organization_leaks(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'finance_admin');
        $foreignContext = AdminApiTestContext::create(roleSlug: 'finance_admin');
        $counterparty = Organization::factory()->verified()->create(['name' => 'Counterparty A']);
        $foreignCounterparty = Organization::factory()->verified()->create(['name' => 'Counterparty B']);
        $this->activatePaymentsModule($context->organization->id);

        $this->createDocument($context->organization, $counterparty, InvoiceDirection::INCOMING, 1000, 700, PaymentDocumentStatus::APPROVED, 'IN-001');
        $this->createDocument($context->organization, $counterparty, InvoiceDirection::OUTGOING, 400, 250, PaymentDocumentStatus::SCHEDULED, 'OUT-001');
        $this->createDocument($context->organization, $foreignCounterparty, InvoiceDirection::INCOMING, 500, 500, PaymentDocumentStatus::CANCELLED, 'CANCELLED-001');
        $this->createDocument($foreignContext->organization, $foreignCounterparty, InvoiceDirection::INCOMING, 900, 900, PaymentDocumentStatus::APPROVED, 'FOREIGN-001');

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/payments/counterparty-accounts');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.counterparty_organization_id', $counterparty->id);
        $response->assertJsonPath('data.0.counterparty_name', 'Counterparty A');
        $response->assertJsonPath('data.0.receivable', '700');
        $response->assertJsonPath('data.0.payable', '250');
        $response->assertJsonPath('data.0.balance', '450');
        $response->assertJsonPath('data.0.documents.their_debts.0.document_number', 'IN-001');
        $response->assertJsonPath('data.0.documents.our_debts.0.document_number', 'OUT-001');
        $response->assertJsonPath('data.0.invoices.their_debts.0.document_number', 'IN-001');
    }

    public function test_counterparty_account_details_are_scoped_to_current_organization(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'finance_admin');
        $foreignContext = AdminApiTestContext::create(roleSlug: 'finance_admin');
        $counterparty = Organization::factory()->verified()->create(['name' => 'Scoped Counterparty']);
        $this->activatePaymentsModule($context->organization->id);

        $this->createDocument($context->organization, $counterparty, InvoiceDirection::INCOMING, 1500, 1200, PaymentDocumentStatus::PARTIALLY_PAID, 'OWN-001');
        $this->createDocument($foreignContext->organization, $counterparty, InvoiceDirection::OUTGOING, 3000, 3000, PaymentDocumentStatus::APPROVED, 'LEAK-001');

        $response = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/payments/counterparty-accounts/{$counterparty->id}");

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.counterparty_organization_id', $counterparty->id);
        $response->assertJsonPath('data.receivable', '1200');
        $response->assertJsonPath('data.payable', '0');
        $response->assertJsonPath('data.documents.their_debts.0.document_number', 'OWN-001');
        $response->assertJsonCount(0, 'data.documents.our_debts');
    }

    public function test_web_admin_has_counterparty_account_access_after_payments_permissions_assignment(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $counterparty = Organization::factory()->verified()->create(['name' => 'Web Admin Counterparty']);
        $this->activatePaymentsModule($context->organization->id);

        $this->createDocument($context->organization, $counterparty, InvoiceDirection::INCOMING, 800, 800, PaymentDocumentStatus::APPROVED, 'WEB-001');

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/payments/counterparty-accounts');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.0.counterparty_organization_id', $counterparty->id);
    }

    private function createDocument(
        Organization $organization,
        Organization $counterparty,
        InvoiceDirection $direction,
        float $amount,
        float $remainingAmount,
        PaymentDocumentStatus $status,
        string $number
    ): PaymentDocument {
        return PaymentDocument::query()->create([
            'organization_id' => $organization->id,
            'document_type' => PaymentDocumentType::INVOICE,
            'document_number' => $number,
            'document_date' => now()->toDateString(),
            'direction' => $direction,
            'payer_organization_id' => $direction === InvoiceDirection::OUTGOING ? $organization->id : $counterparty->id,
            'payee_organization_id' => $direction === InvoiceDirection::OUTGOING ? $counterparty->id : $organization->id,
            'counterparty_organization_id' => $counterparty->id,
            'amount' => $amount,
            'remaining_amount' => $remainingAmount,
            'paid_amount' => $amount - $remainingAmount,
            'status' => $status,
            'due_date' => now()->addDays(7)->toDateString(),
        ]);
    }

    private function activatePaymentsModule(int $organizationId): void
    {
        $module = Module::query()->firstOrCreate(
            ['slug' => 'payments'],
            [
                'name' => 'Payments',
                'version' => '1.0.0',
                'type' => 'core',
                'billing_model' => 'free',
                'category' => 'finance',
                'permissions' => ['payments.counterparty_account.view'],
                'is_active' => true,
                'is_system_module' => false,
            ]
        );

        OrganizationModuleActivation::query()->create([
            'organization_id' => $organizationId,
            'module_id' => $module->id,
            'status' => 'active',
            'activated_at' => now(),
        ]);
    }
}
