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
use App\Models\OrganizationModuleActivation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class DashboardControllerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_counts_only_current_organization_documents_and_transactions(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $foreignContext = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $this->activatePaymentsModule($foreignContext->organization->id);

        $payer = $this->createContractor($context->organization->id, 'Dashboard payer');
        $payee = $this->createContractor($context->organization->id, 'Dashboard payee');
        $incoming = $this->createDocument($context, [
            'direction' => InvoiceDirection::INCOMING,
            'payer_contractor_id' => $payer->id,
            'contractor_id' => $payer->id,
            'amount' => 1000,
            'paid_amount' => 200,
            'remaining_amount' => 800,
        ]);
        $outgoing = $this->createDocument($context, [
            'direction' => InvoiceDirection::OUTGOING,
            'payee_contractor_id' => $payee->id,
            'contractor_id' => $payee->id,
            'amount' => 400,
            'paid_amount' => 100,
            'remaining_amount' => 300,
        ]);
        $paid = $this->createDocument($context, [
            'direction' => InvoiceDirection::OUTGOING,
            'status' => PaymentDocumentStatus::PAID,
            'amount' => 50,
            'paid_amount' => 50,
            'remaining_amount' => 0,
            'paid_at' => now(),
        ]);

        $foreignDocument = $this->createDocument($foreignContext, [
            'direction' => InvoiceDirection::INCOMING,
            'amount' => 9900,
            'paid_amount' => 0,
            'remaining_amount' => 9900,
        ]);

        $this->createTransaction($incoming, 200);
        $this->createTransaction($outgoing, 50);
        $this->createTransaction($paid, 50);
        $this->createTransaction($foreignDocument, 9900);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/payments/dashboard?period=7');

        $response->assertOk();
        $response->assertJsonPath('data.summary.total_receivable', 800);
        $response->assertJsonPath('data.summary.total_payable', 300);
        $response->assertJsonPath('data.summary.net_position', 500);
        $response->assertJsonPath('data.summary.active_documents_count', 2);
        $response->assertJsonPath('data.summary.paid_documents_count', 1);
        $response->assertJsonPath('data.documents_by_status.approved.count', 2);
        $response->assertJsonPath('data.documents_by_status.paid.count', 1);
        $response->assertJsonPath('data.cash_flow.period_days', 7);
        $response->assertJsonPath('data.cash_flow.incoming', 200);
        $response->assertJsonPath('data.cash_flow.outgoing', 100);
        $response->assertJsonPath('data.cash_flow.net_cash_flow', 100);
    }

    private function createDocument(AdminApiTestContext $context, array $overrides = []): PaymentDocument
    {
        return PaymentDocument::query()->create(array_merge([
            'organization_id' => $context->organization->id,
            'document_type' => PaymentDocumentType::INVOICE,
            'document_number' => 'DASH-' . uniqid(),
            'document_date' => now()->toDateString(),
            'direction' => InvoiceDirection::OUTGOING,
            'amount' => 1000,
            'paid_amount' => 0,
            'remaining_amount' => 1000,
            'currency' => 'RUB',
            'status' => PaymentDocumentStatus::APPROVED,
            'due_date' => now()->addDays(3)->toDateString(),
        ], $overrides));
    }

    private function createTransaction(PaymentDocument $document, float $amount): PaymentTransaction
    {
        return PaymentTransaction::query()->create([
            'payment_document_id' => $document->id,
            'organization_id' => $document->organization_id,
            'project_id' => $document->project_id,
            'amount' => $amount,
            'currency' => 'RUB',
            'payment_method' => PaymentMethod::BANK_TRANSFER,
            'transaction_date' => now()->toDateString(),
            'status' => PaymentTransactionStatus::COMPLETED,
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
                'permissions' => ['payments.dashboard.view'],
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
