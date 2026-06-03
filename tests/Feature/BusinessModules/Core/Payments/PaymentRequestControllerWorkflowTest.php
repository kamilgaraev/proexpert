<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessModules\Core\Payments;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Models\CounterpartyAccount;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\Models\Contractor;
use App\Models\Module;
use App\Models\OrganizationModuleActivation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class PaymentRequestControllerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_request_can_be_created_even_when_counterparty_account_exists(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $contractor = $this->createContractor($context);

        CounterpartyAccount::query()->create([
            'organization_id' => $context->organization->id,
            'counterparty_contractor_id' => $contractor->id,
            'receivable_balance' => 0,
            'payable_balance' => 0,
            'net_balance' => 0,
            'is_active' => true,
            'is_blocked' => false,
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/payments/requests', [
                'contractor_id' => $contractor->id,
                'amount' => 1500,
                'currency' => 'RUB',
                'payment_purpose' => 'Оплата работ по акту',
                'bank_account' => '40702810000000000001',
                'bank_bik' => '044525225',
                'bank_correspondent_account' => '30101810400000000225',
                'bank_name' => 'Банк',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.contractor.id', $contractor->id);
        $response->assertJsonPath('data.amount', 1500);

        $this->assertDatabaseHas('payment_documents', [
            'organization_id' => $context->organization->id,
            'payee_contractor_id' => $contractor->id,
            'document_type' => PaymentDocumentType::PAYMENT_REQUEST->value,
            'payment_purpose' => 'Оплата работ по акту',
        ]);
    }

    public function test_approved_payment_request_can_be_accepted_into_payment_order(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $contractor = $this->createContractor($context);
        $request = $this->createPaymentRequest($context, $contractor, PaymentDocumentStatus::APPROVED);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/payments/requests/{$request->id}/accept", [
                'scheduled_at' => now()->addDays(3)->toDateString(),
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.request.id', $request->id);
        $response->assertJsonPath('data.payment_order.status', PaymentDocumentStatus::DRAFT->value);

        $this->assertDatabaseHas('payment_documents', [
            'source_id' => $request->id,
            'document_type' => PaymentDocumentType::PAYMENT_ORDER->value,
            'payee_contractor_id' => $contractor->id,
            'amount' => '900.00',
        ]);
    }

    public function test_payment_request_rejection_sets_rejected_status_and_statistics_count_it(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $contractor = $this->createContractor($context);
        $request = $this->createPaymentRequest($context, $contractor, PaymentDocumentStatus::SUBMITTED);

        $rejectResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/payments/requests/{$request->id}/reject", [
                'reason' => 'Нет закрывающих документов',
            ]);

        $rejectResponse->assertOk();
        $rejectResponse->assertJsonPath('data.status', PaymentDocumentStatus::REJECTED->value);

        $statisticsResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/payments/requests/statistics');

        $statisticsResponse->assertOk();
        $statisticsResponse->assertJsonPath('data.rejected_count', 1);
    }

    public function test_incoming_requests_accept_pending_status_alias(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $contractor = $this->createContractor($context);
        $pending = $this->createPaymentRequest($context, $contractor, PaymentDocumentStatus::PENDING_APPROVAL);
        $this->createPaymentRequest($context, $contractor, PaymentDocumentStatus::APPROVED);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/payments/requests/incoming?status=pending&per_page=20');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $pending->id);
        $response->assertJsonPath('data.0.status', PaymentDocumentStatus::PENDING_APPROVAL->value);
    }

    private function createContractor(AdminApiTestContext $context): Contractor
    {
        return Contractor::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Request Contractor',
            'inn' => '7700000010',
        ]);
    }

    private function createPaymentRequest(
        AdminApiTestContext $context,
        Contractor $contractor,
        PaymentDocumentStatus $status
    ): PaymentDocument {
        return PaymentDocument::query()->create([
            'organization_id' => $context->organization->id,
            'payer_organization_id' => $context->organization->id,
            'payee_contractor_id' => $contractor->id,
            'contractor_id' => $contractor->id,
            'document_type' => PaymentDocumentType::PAYMENT_REQUEST,
            'document_number' => 'REQ-' . uniqid(),
            'document_date' => now()->toDateString(),
            'direction' => InvoiceDirection::OUTGOING,
            'amount' => 900,
            'paid_amount' => 0,
            'remaining_amount' => 900,
            'currency' => 'RUB',
            'status' => $status,
            'payment_purpose' => 'Оплата работ',
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
                'permissions' => [
                    'payments.invoice.view',
                    'payments.invoice.create',
                    'payments.transaction.approve',
                    'payments.transaction.reject',
                ],
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
