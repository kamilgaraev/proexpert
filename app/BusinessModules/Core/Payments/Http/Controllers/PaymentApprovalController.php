<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Http\Controllers;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Services\ApprovalWorkflowService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

use function trans_message;

class PaymentApprovalController extends Controller
{
    public function __construct(
        private readonly ApprovalWorkflowService $approvalService
    ) {}

    public function myApprovals(Request $request): JsonResponse
    {
        try {
            $userId = (int) $request->user()->id;
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $approvals = $this->approvalService->getPendingApprovalsForUser($userId, $organizationId);

            return AdminResponse::paginated(
                $approvals->map(fn ($approval) => [
                    'payment_document_id' => $approval->payment_document_id,
                    'payment_document' => [
                        'id' => $approval->paymentDocument->id,
                        'document_number' => $approval->paymentDocument->document_number,
                        'document_type' => $approval->paymentDocument->document_type->value,
                        'document_type_label' => $approval->paymentDocument->document_type->label(),
                        'amount' => $approval->paymentDocument->amount,
                        'currency' => $approval->paymentDocument->currency,
                        'payer_name' => $approval->paymentDocument->getPayerName(),
                        'payee_name' => $approval->paymentDocument->getPayeeName(),
                        'description' => $approval->paymentDocument->description,
                    ],
                    'approval_role' => $approval->approval_role,
                    'approval_role_label' => $approval->getRoleLabel(),
                    'approval_level' => $approval->approval_level,
                    'approval_order' => $approval->approval_order,
                    'amount_threshold' => $approval->amount_threshold,
                    'can_approve' => $approval->canApproveAmount($approval->paymentDocument->amount),
                    'notified_at' => $approval->notified_at?->toDateTimeString(),
                    'reminder_count' => $approval->reminder_count,
                    'created_at' => $approval->created_at->toDateTimeString(),
                ]),
                [
                    'total' => $approvals->count(),
                    'total_amount' => $approvals->sum(fn ($approval) => $approval->paymentDocument->amount),
                ],
                trans_message('payments.approval.loaded')
            );
        } catch (\Exception $e) {
            Log::error('payment_approval.my_approvals.error', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.approval.load_error'), 500);
        }
    }

    public function approve(Request $request, int|string $documentId): JsonResponse
    {
        $resolvedDocumentId = $this->resolveDocumentId($documentId);
        if ($resolvedDocumentId === null) {
            return AdminResponse::error(trans_message('payments.approval.invalid_document_id'), 422);
        }

        try {
            $validated = $request->validate([
                'comment' => ['nullable', 'string'],
            ]);

            $organizationId = (int) $request->attributes->get('current_organization_id');
            $userId = (int) $request->user()->id;
            $document = PaymentDocument::query()
                ->forOrganization($organizationId)
                ->findOrFail($resolvedDocumentId);

            $this->approvalService->approveByUser($document, $userId, $validated['comment'] ?? null);

            return AdminResponse::success([
                'approval_status' => $this->approvalService->getApprovalStatus($document),
            ], trans_message('payments.approval.approved'));
        } catch (\DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return AdminResponse::error(trans_message('payments.validation_error'), 422, $e->errors());
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return AdminResponse::error(trans_message('payments.not_found'), 404);
        } catch (\Exception $e) {
            Log::error('payment_approval.approve.error', [
                'document_id' => $resolvedDocumentId,
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.approval.approve_error'), 500);
        }
    }

    public function reject(Request $request, int|string $documentId): JsonResponse
    {
        $resolvedDocumentId = $this->resolveDocumentId($documentId);
        if ($resolvedDocumentId === null) {
            return AdminResponse::error(trans_message('payments.approval.invalid_document_id'), 422);
        }

        try {
            $validated = $request->validate([
                'reason' => ['required', 'string', 'min:3'],
            ]);

            $organizationId = (int) $request->attributes->get('current_organization_id');
            $userId = (int) $request->user()->id;
            $document = PaymentDocument::query()
                ->forOrganization($organizationId)
                ->findOrFail($resolvedDocumentId);

            $this->approvalService->rejectByUser($document, $userId, $validated['reason']);

            return AdminResponse::success([
                'approval_status' => $this->approvalService->getApprovalStatus($document),
            ], trans_message('payments.approval.rejected'));
        } catch (\DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return AdminResponse::error(trans_message('payments.validation_error'), 422, $e->errors());
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return AdminResponse::error(trans_message('payments.not_found'), 404);
        } catch (\Exception $e) {
            Log::error('payment_approval.reject.error', [
                'document_id' => $resolvedDocumentId,
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.approval.reject_error'), 500);
        }
    }

    public function history(Request $request, int|string $documentId): JsonResponse
    {
        $resolvedDocumentId = $this->resolveDocumentId($documentId);
        if ($resolvedDocumentId === null) {
            return AdminResponse::error(trans_message('payments.approval.invalid_document_id'), 422);
        }

        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $document = PaymentDocument::query()
                ->forOrganization($organizationId)
                ->findOrFail($resolvedDocumentId);
            $history = $this->approvalService->getApprovalHistory($document);

            return AdminResponse::success(
                $history->map(fn ($approval) => [
                    'id' => $approval->id,
                    'role' => $approval->approval_role,
                    'role_label' => $approval->getRoleLabel(),
                    'approver' => $approval->approver ? [
                        'id' => $approval->approver->id,
                        'name' => $approval->approver->name,
                    ] : null,
                    'level' => $approval->approval_level,
                    'order' => $approval->approval_order,
                    'status' => $approval->status,
                    'status_label' => $approval->getStatusLabel(),
                    'comment' => $approval->decision_comment,
                    'decided_at' => $approval->decided_at?->toDateTimeString(),
                    'created_at' => $approval->created_at->toDateTimeString(),
                ]),
                trans_message('payments.approval.loaded')
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return AdminResponse::error(trans_message('payments.not_found'), 404);
        } catch (\Exception $e) {
            Log::error('payment_approval.history.error', [
                'document_id' => $resolvedDocumentId,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.approval.load_error'), 500);
        }
    }

    public function status(Request $request, int|string $documentId): JsonResponse
    {
        $resolvedDocumentId = $this->resolveDocumentId($documentId);
        if ($resolvedDocumentId === null) {
            return AdminResponse::error(trans_message('payments.approval.invalid_document_id'), 422);
        }

        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $document = PaymentDocument::query()
                ->forOrganization($organizationId)
                ->findOrFail($resolvedDocumentId);
            $status = $this->approvalService->getApprovalStatus($document, (int) $request->user()->id);

            return AdminResponse::success($status, trans_message('payments.approval.loaded'));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return AdminResponse::error(trans_message('payments.not_found'), 404);
        } catch (\Exception $e) {
            Log::error('payment_approval.status.error', [
                'document_id' => $resolvedDocumentId,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.approval.load_error'), 500);
        }
    }

    public function sendReminders(Request $request, int|string $documentId): JsonResponse
    {
        $resolvedDocumentId = $this->resolveDocumentId($documentId);
        if ($resolvedDocumentId === null) {
            return AdminResponse::error(trans_message('payments.approval.invalid_document_id'), 422);
        }

        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $document = PaymentDocument::query()
                ->forOrganization($organizationId)
                ->findOrFail($resolvedDocumentId);
            $sentCount = $this->approvalService->sendReminders($document);

            return AdminResponse::success([
                'sent_count' => $sentCount,
            ], trans_message('payments.approval.reminders_sent'));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return AdminResponse::error(trans_message('payments.not_found'), 404);
        } catch (\Exception $e) {
            Log::error('payment_approval.send_reminders.error', [
                'document_id' => $resolvedDocumentId,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.approval.reminders_error'), 500);
        }
    }

    private function resolveDocumentId(int|string $documentId): ?int
    {
        if (!is_numeric($documentId)) {
            return null;
        }

        return (int) $documentId;
    }
}
