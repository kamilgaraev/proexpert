<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessModules\Core\Payments;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Models\PaymentApprovalRule;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Services\ApprovalWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class ApprovalWorkflowServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_is_auto_approved_without_pending_approval_records(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $document = PaymentDocument::query()->create([
            'organization_id' => $context->organization->id,
            'document_type' => PaymentDocumentType::INVOICE,
            'document_number' => 'INV-NO-APPROVAL',
            'document_date' => now()->toDateString(),
            'direction' => InvoiceDirection::OUTGOING,
            'amount' => 1800,
            'paid_amount' => 0,
            'remaining_amount' => 1800,
            'status' => PaymentDocumentStatus::SUBMITTED,
            'due_date' => now()->addDays(7)->toDateString(),
        ]);

        PaymentApprovalRule::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Общее правило согласования',
            'conditions' => [],
            'approval_chain' => [[
                'level' => 1,
                'order' => 1,
                'approval_permission' => 'payments.transaction.approve',
            ]],
            'is_active' => true,
        ]);

        $approvals = app(ApprovalWorkflowService::class)->initiateApproval($document);

        $this->assertCount(0, $approvals);
        $this->assertSame(PaymentDocumentStatus::APPROVED, $document->fresh()->status);
        $this->assertDatabaseMissing('payment_approvals', [
            'payment_document_id' => $document->id,
            'status' => 'pending',
        ]);
    }
}
