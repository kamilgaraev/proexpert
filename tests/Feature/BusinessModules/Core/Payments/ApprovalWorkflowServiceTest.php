<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessModules\Core\Payments;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Models\PaymentApproval;
use App\BusinessModules\Core\Payments\Models\PaymentApprovalRule;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Services\ApprovalWorkflowService;
use App\BusinessModules\Core\Payments\Services\PaymentDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class ApprovalWorkflowServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_queue_excludes_cancelled_documents_without_erasing_history(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $cancelled = $this->createDocument($context, PaymentDocumentStatus::CANCELLED);
        $obsolete = $this->createApproval($cancelled, $context->user->id);
        $active = $this->createDocument($context, PaymentDocumentStatus::PENDING_APPROVAL);
        $expected = $this->createApproval($active, $context->user->id);

        $queue = app(ApprovalWorkflowService::class)->getPendingApprovalsForUser($context->user->id, $context->organization->id);

        $this->assertSame([$expected->id], $queue->modelKeys());
        $this->assertSame('pending', $obsolete->fresh()->status);
        $this->assertSame(PaymentDocumentStatus::CANCELLED, $cancelled->fresh()->status);
    }

    public function test_cancellation_closes_pending_approvals_and_preserves_completed_decisions(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $document = $this->createDocument($context, PaymentDocumentStatus::PENDING_APPROVAL);
        $pending = $this->createApproval($document, $context->user->id);
        $completed = $this->createApproval($document, $context->user->id);
        $completed->update(['status' => 'approved', 'decision_comment' => 'Согласовано ранее', 'decided_at' => now()->subDay()]);
        $history = $completed->fresh()->getAttributes();

        app(PaymentDocumentService::class)->cancel($document, 'Ошибочно оформленный счёт', $context->user);

        $this->assertSame('skipped', $pending->fresh()->status);
        $this->assertSame($history, $completed->fresh()->getAttributes());
        $this->assertSame(PaymentDocumentStatus::CANCELLED, $document->fresh()->status);
        $this->assertSame('1800.00', $document->fresh()->amount);
    }

    public function test_cancelled_document_cannot_accept_partial_approval(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $document = $this->createDocument($context, PaymentDocumentStatus::CANCELLED);
        $first = $this->createApproval($document, $context->user->id);
        $second = $this->createApproval($document, $context->user->id);
        $second->update(['approval_level' => 2]);

        try {
            app(ApprovalWorkflowService::class)->approveByUser($document, $context->user->id);
            $this->fail('Cancelled documents must not accept approval decisions.');
        } catch (\DomainException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }

        $this->assertSame('pending', $first->fresh()->status);
        $this->assertSame('pending', $second->fresh()->status);
        $this->assertSame(PaymentDocumentStatus::CANCELLED, $document->fresh()->status);
    }

    public function test_queue_does_not_create_approval_after_document_was_cancelled_during_read(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $document = $this->createDocument($context, PaymentDocumentStatus::PENDING_APPROVAL);
        $cancelledDuringRead = false;

        PaymentDocument::retrieved(function (PaymentDocument $loaded) use ($document, $context, &$cancelledDuringRead): void {
            if ($loaded->id !== $document->id || $cancelledDuringRead) {
                return;
            }

            $cancelledDuringRead = true;
            app(PaymentDocumentService::class)->cancel($loaded, 'Отменён во время открытия очереди', $context->user);
        });

        $queue = app(ApprovalWorkflowService::class)->getPendingApprovalsForUser($context->user->id, $context->organization->id);

        $this->assertTrue($cancelledDuringRead);
        $this->assertCount(0, $queue);
        $this->assertSame(0, $document->approvals()->where('status', 'pending')->count());
        $this->assertSame(PaymentDocumentStatus::CANCELLED, $document->fresh()->status);
    }

    public function test_owner_queue_creates_only_one_missing_approval_for_active_document(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $document = $this->createDocument($context, PaymentDocumentStatus::PENDING_APPROVAL);
        $service = app(ApprovalWorkflowService::class);

        $first = $service->getPendingApprovalsForUser($context->user->id, $context->organization->id);
        $second = $service->getPendingApprovalsForUser($context->user->id, $context->organization->id);

        $this->assertCount(1, $first);
        $this->assertSame($first->modelKeys(), $second->modelKeys());
        $this->assertSame($document->id, $first->first()->payment_document_id);
        $this->assertSame(1, $document->approvals()->count());
    }

    private function createDocument(AdminApiTestContext $context, PaymentDocumentStatus $status): PaymentDocument
    {
        return PaymentDocument::query()->create([
            'organization_id' => $context->organization->id,
            'document_type' => PaymentDocumentType::INVOICE,
            'document_number' => 'TEST-'.\Illuminate\Support\Str::uuid(),
            'document_date' => now()->toDateString(),
            'direction' => InvoiceDirection::OUTGOING,
            'amount' => 1800,
            'paid_amount' => 0,
            'remaining_amount' => 1800,
            'status' => $status,
            'due_date' => now()->addDays(7)->toDateString(),
        ]);
    }

    private function createApproval(PaymentDocument $document, int $userId): PaymentApproval
    {
        return PaymentApproval::query()->create([
            'organization_id' => $document->organization_id,
            'payment_document_id' => $document->id,
            'approver_user_id' => $userId,
            'approval_permission' => 'payments.transaction.approve',
            'approval_level' => 1,
            'approval_order' => 1,
            'status' => 'pending',
        ]);
    }

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
