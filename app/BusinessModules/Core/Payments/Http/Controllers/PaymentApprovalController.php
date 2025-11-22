<?php

namespace App\BusinessModules\Core\Payments\Http\Controllers;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Services\ApprovalWorkflowService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentApprovalController extends Controller
{
    public function __construct(
        private readonly ApprovalWorkflowService $approvalService
    ) {}

    /**
     * Получить pending утверждения для текущего пользователя
     */
    public function myApprovals(Request $request): JsonResponse
    {
        try {
            $userId = $request->user()->id;
            $organizationId = $request->attributes->get('current_organization_id');
            $approvals = $this->approvalService->getPendingApprovalsForUser($userId, $organizationId);

            return response()->json([
                'success' => true,
                'data' => $approvals->map(fn($approval) => [
                    'id' => $approval->id,
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
                'meta' => [
                    'total' => $approvals->count(),
                    'total_amount' => $approvals->sum(fn($a) => $a->paymentDocument->amount),
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('payment_approval.my_approvals.error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить утверждения',
            ], 500);
        }
    }

    /**
     * Утвердить документ
     */
    public function approve(Request $request, int $documentId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'comment' => 'nullable|string',
            ]);

            $organizationId = $request->attributes->get('current_organization_id');
            $userId = $request->user()->id;

            $document = PaymentDocument::forOrganization($organizationId)->findOrFail($documentId);

            $this->approvalService->approveByUser(
                $document,
                $userId,
                $validated['comment'] ?? null
            );

            return response()->json([
                'success' => true,
                'message' => 'Документ утвержден',
                'data' => [
                    'approval_status' => $this->approvalService->getApprovalStatus($document),
                ],
            ]);
        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('payment_approval.approve.error', [
                'document_id' => $documentId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось утвердить документ',
            ], 500);
        }
    }

    /**
     * Отклонить документ
     */
    public function reject(Request $request, int $documentId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'reason' => 'required|string|min:3',
            ]);

            $organizationId = $request->attributes->get('current_organization_id');
            $userId = $request->user()->id;

            $document = PaymentDocument::forOrganization($organizationId)->findOrFail($documentId);

            $this->approvalService->rejectByUser(
                $document,
                $userId,
                $validated['reason']
            );

            return response()->json([
                'success' => true,
                'message' => 'Документ отклонен',
                'data' => [
                    'approval_status' => $this->approvalService->getApprovalStatus($document),
                ],
            ]);
        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('payment_approval.reject.error', [
                'document_id' => $documentId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось отклонить документ',
            ], 500);
        }
    }

    /**
     * Получить историю утверждений документа
     */
    public function history(Request $request, int $documentId): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $document = PaymentDocument::forOrganization($organizationId)->findOrFail($documentId);

            $history = $this->approvalService->getApprovalHistory($document);

            return response()->json([
                'success' => true,
                'data' => $history->map(fn($approval) => [
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
            ]);
        } catch (\Exception $e) {
            \Log::error('payment_approval.history.error', [
                'document_id' => $documentId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить историю',
            ], 404);
        }
    }

    /**
     * Получить статус утверждения документа
     */
    public function status(Request $request, int $documentId): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $document = PaymentDocument::forOrganization($organizationId)->findOrFail($documentId);

            $status = $this->approvalService->getApprovalStatus($document);

            return response()->json([
                'success' => true,
                'data' => $status,
            ]);
        } catch (\Exception $e) {
            \Log::error('payment_approval.status.error', [
                'document_id' => $documentId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить статус',
            ], 404);
        }
    }

    /**
     * Отправить напоминания утверждающим
     */
    public function sendReminders(Request $request, int $documentId): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $document = PaymentDocument::forOrganization($organizationId)->findOrFail($documentId);

            $sentCount = $this->approvalService->sendReminders($document);

            return response()->json([
                'success' => true,
                'message' => "Отправлено напоминаний: {$sentCount}",
                'data' => [
                    'sent_count' => $sentCount,
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('payment_approval.send_reminders.error', [
                'document_id' => $documentId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось отправить напоминания',
            ], 500);
        }
    }
}

