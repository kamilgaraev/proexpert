<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessModules\Core\Payments;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Enums\PaymentMethod;
use App\BusinessModules\Core\Payments\Enums\PaymentTransactionStatus;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use App\Models\Contractor;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModuleActivation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class PaymentReportsControllerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_cash_flow_report_uses_only_current_organization_transactions(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $currentPayer = $this->createContractor($context->organization->id, 'Current payer');
        $currentPayee = $this->createContractor($context->organization->id, 'Current payee');

        $incomingDocument = $this->createDocument($context->organization->id, [
            'payer_contractor_id' => $currentPayer->id,
            'direction' => InvoiceDirection::INCOMING,
            'amount' => 1200,
            'remaining_amount' => 0,
        ]);
        $outgoingDocument = $this->createDocument($context->organization->id, [
            'payee_contractor_id' => $currentPayee->id,
            'direction' => InvoiceDirection::OUTGOING,
            'amount' => 400,
            'remaining_amount' => 0,
        ]);

        $this->createTransaction($incomingDocument, ['payer_contractor_id' => $currentPayer->id, 'amount' => 1200]);
        $this->createTransaction($outgoingDocument, ['payee_contractor_id' => $currentPayee->id, 'amount' => 400]);
        $this->createForeignCompletedTransaction();

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/payments/reports/cash-flow?date_from=2026-06-01&date_to=2026-06-30');

        $response->assertOk();
        $response->assertJsonPath('data.summary.total_inflow', 1200);
        $response->assertJsonPath('data.summary.total_outflow', 400);
        $response->assertJsonPath('data.summary.net_cash_flow', 800);
        $response->assertJsonCount(1, 'data.inflows_by_contractor');
        $response->assertJsonCount(1, 'data.outflows_by_contractor');
    }

    public function test_optional_payment_report_filters_accept_empty_query_values(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);

        $agingResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/payments/reports/aging-analysis?as_of_date=');
        $criticalResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/payments/reports/critical-contractors?min_days_overdue=');

        $agingResponse->assertOk();
        $agingResponse->assertJsonPath('success', true);
        $criticalResponse->assertOk();
        $criticalResponse->assertJsonPath('success', true);
    }

    public function test_cash_flow_report_rejects_missing_required_period(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/payments/reports/cash-flow?date_from=&date_to=');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['date_from', 'date_to']);
    }

    private function createDocument(int $organizationId, array $overrides = []): PaymentDocument
    {
        return PaymentDocument::query()->create(array_merge([
            'organization_id' => $organizationId,
            'document_type' => PaymentDocumentType::INVOICE,
            'document_number' => 'REP-' . uniqid(),
            'document_date' => '2026-06-10',
            'direction' => InvoiceDirection::OUTGOING,
            'amount' => 1000,
            'paid_amount' => 0,
            'remaining_amount' => 1000,
            'currency' => 'RUB',
            'status' => PaymentDocumentStatus::APPROVED,
            'due_date' => '2026-06-20',
        ], $overrides));
    }

    private function createTransaction(PaymentDocument $document, array $overrides = []): PaymentTransaction
    {
        return PaymentTransaction::query()->create(array_merge([
            'payment_document_id' => $document->id,
            'organization_id' => $document->organization_id,
            'project_id' => $document->project_id,
            'amount' => 250,
            'currency' => 'RUB',
            'payment_method' => PaymentMethod::BANK_TRANSFER,
            'transaction_date' => '2026-06-15',
            'status' => PaymentTransactionStatus::COMPLETED,
        ], $overrides));
    }

    private function createForeignCompletedTransaction(): void
    {
        $foreignOrganization = Organization::factory()->create();
        $this->activatePaymentsModule($foreignOrganization->id);
        $foreignContractor = $this->createContractor($foreignOrganization->id, 'Foreign payer');
        $foreignDocument = $this->createDocument($foreignOrganization->id, [
            'payer_contractor_id' => $foreignContractor->id,
            'direction' => InvoiceDirection::INCOMING,
            'amount' => 9900,
            'remaining_amount' => 0,
        ]);

        $this->createTransaction($foreignDocument, [
            'payer_contractor_id' => $foreignContractor->id,
            'amount' => 9900,
        ]);
    }

    private function createContractor(int $organizationId, string $name): Contractor
    {
        return Contractor::query()->create([
            'organization_id' => $organizationId,
            'name' => $name,
            'inn' => (string) random_int(1000000000, 9999999999),
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
                'permissions' => ['payments.reports.view'],
                'is_active' => true,
                'is_system_module' => false,
            ]
        );

        OrganizationModuleActivation::query()->firstOrCreate(
            [
                'organization_id' => $organizationId,
                'module_id' => $module->id,
            ],
            [
                'status' => 'active',
                'activated_at' => now(),
            ]
        );
    }
}
