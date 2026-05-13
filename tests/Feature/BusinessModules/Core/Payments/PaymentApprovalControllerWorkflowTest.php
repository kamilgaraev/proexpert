<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessModules\Core\Payments;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Models\PaymentApproval;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\Models\Module;
use App\Models\OrganizationModuleActivation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class PaymentApprovalControllerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_approve_accepts_admin_notes_contract_and_persists_decision_comment(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $document = $this->createPendingApprovalDocument($context);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/payments/approvals/documents/{$document->id}/approve", [
                'notes' => 'Проверено бухгалтерией',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.approval_status.is_fully_approved', true);

        $this->assertDatabaseHas('payment_approvals', [
            'payment_document_id' => $document->id,
            'approver_user_id' => $context->user->id,
            'status' => 'approved',
            'decision_comment' => 'Проверено бухгалтерией',
        ]);
    }

    public function test_reject_returns_approval_status_payload_and_persists_reason(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $document = $this->createPendingApprovalDocument($context);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/payments/approvals/documents/{$document->id}/reject", [
                'reason' => 'Нет подтверждающих документов',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.approval_status.is_rejected', true);

        $this->assertDatabaseHas('payment_approvals', [
            'payment_document_id' => $document->id,
            'approver_user_id' => $context->user->id,
            'status' => 'rejected',
            'decision_comment' => 'Нет подтверждающих документов',
        ]);
    }

    public function test_my_approvals_returns_admin_queue_payload_with_pagination_and_tenant_scope(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $document = $this->createPendingApprovalDocument($context);

        $foreignContext = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($foreignContext->organization->id);
        $foreignDocument = $this->createPendingApprovalDocument($foreignContext);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/payments/approvals/my?status=pending&per_page=15&page=1');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('meta.current_page', 1);
        $response->assertJsonPath('meta.per_page', 15);
        $response->assertJsonPath('meta.total', 1);
        $this->assertEquals(1000.0, $response->json('summary.total_amount'));

        $ids = collect($response->json('data'))->pluck('payment_document_id')->all();
        $this->assertContains($document->id, $ids);
        $this->assertNotContains($foreignDocument->id, $ids);

        $approval = $response->json('data.0');
        $this->assertIsInt($approval['id']);
        $this->assertSame('pending', $approval['status']);
        $this->assertArrayHasKey('due_date', $approval);
        $this->assertSame($document->document_number, $approval['payment_document']['document_number']);
    }

    private function createPendingApprovalDocument(AdminApiTestContext $context): PaymentDocument
    {
        $document = PaymentDocument::query()->create([
            'organization_id' => $context->organization->id,
            'document_type' => PaymentDocumentType::INVOICE,
            'document_number' => 'APP-' . uniqid(),
            'document_date' => now()->toDateString(),
            'direction' => InvoiceDirection::OUTGOING,
            'amount' => 1000,
            'paid_amount' => 0,
            'remaining_amount' => 1000,
            'status' => PaymentDocumentStatus::PENDING_APPROVAL,
            'due_date' => now()->addDays(7)->toDateString(),
        ]);

        PaymentApproval::query()->create([
            'payment_document_id' => $document->id,
            'organization_id' => $context->organization->id,
            'approval_role' => 'chief_accountant',
            'approver_user_id' => $context->user->id,
            'approval_level' => 1,
            'approval_order' => 1,
            'status' => 'pending',
        ]);

        return $document;
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
                    'payments.transaction.approve',
                    'payments.transaction.reject',
                    'payments.transaction.view',
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
