<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Services;

use App\BusinessModules\Core\Payments\Models\PaymentApproval;
use App\BusinessModules\Core\Payments\Models\PaymentAuditLog;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class PaymentDocumentActivityService
{
    public function __construct(
        private readonly PaymentDocumentAccessService $documents,
        private readonly PaymentTransactionAccessService $transactions,
    ) {}

    public function forDocument(int $organizationId, User $actor, int|string $documentId): array
    {
        $documentId = filter_var($documentId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($documentId === false) {
            throw (new ModelNotFoundException)->setModel(PaymentDocument::class);
        }

        $permission = 'payments.transaction.view';
        $document = $this->documents->find($organizationId, $actor, $documentId, $permission);

        $approvals = PaymentApproval::query()
            ->where('organization_id', $document->organization_id)
            ->where('payment_document_id', $document->id)
            ->with('approver:id,name')
            ->orderBy('approval_level')->orderBy('approval_order')->orderBy('id')
            ->get(['id', 'approval_role', 'approval_permission', 'approver_user_id', 'approval_level',
                'approval_order', 'status', 'decision_comment', 'decided_at', 'created_at']);
        $transactions = $this->transactions->query($organizationId, $actor, $permission)
            ->where('payment_document_id', $document->id)
            ->orderByDesc('transaction_date')->orderByDesc('id')
            ->get(['id', 'payment_document_id', 'amount', 'currency', 'status', 'payment_method',
                'transaction_date', 'reference_number', 'notes', 'created_at']);
        $history = PaymentAuditLog::forDocument($document->id)
            ->where('organization_id', $document->organization_id)
            ->with('user:id,name')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->get(['id', 'action', 'user_id', 'user_name', 'created_at']);

        return [
            'document_id' => $document->id,
            'approvals' => $approvals->map(fn (PaymentApproval $approval) => $this->approval($approval))->all(),
            'transactions' => $transactions->map(fn (PaymentTransaction $transaction) => $this->transaction($transaction))->all(),
            'audit_logs' => $history->map(fn (PaymentAuditLog $event) => $this->audit($event))->all(),
        ];
    }

    private function approval(PaymentApproval $approval): array
    {
        return [
            'id' => $approval->id,
            'role' => $approval->approval_role,
            'role_label' => $approval->getRoleLabel(),
            'approval_permission' => $approval->approval_permission,
            'approval_permission_label' => $approval->getPermissionLabel(),
            'approver' => $approval->approver ? ['id' => $approval->approver->id, 'name' => $approval->approver->name] : null,
            'level' => $approval->approval_level,
            'order' => $approval->approval_order,
            'status' => $approval->status,
            'status_label' => $approval->getStatusLabel(),
            'comment' => $approval->decision_comment,
            'decided_at' => $approval->decided_at?->toIso8601String(),
            'created_at' => $approval->created_at?->toIso8601String(),
        ];
    }

    private function transaction(PaymentTransaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'payment_document_id' => $transaction->payment_document_id,
            'amount' => (float) $transaction->amount,
            'currency' => $transaction->currency,
            'status' => $transaction->status->value,
            'payment_method' => $transaction->payment_method->value,
            'transaction_date' => $transaction->transaction_date?->toDateString(),
            'reference_number' => $transaction->reference_number,
            'notes' => $transaction->notes,
            'created_at' => $transaction->created_at?->toIso8601String(),
        ];
    }

    private function audit(PaymentAuditLog $event): array
    {
        $label = $event->getActionLabel();
        $name = $event->user_name ?: $event->user?->name;

        return [
            'id' => $event->id,
            'action' => $label === $event->action ? trans_message('payments.audit.document_changed') : $label,
            'timestamp' => $event->created_at?->toIso8601String(),
            'user' => $event->user_id !== null || $name !== null ? ['id' => $event->user_id, 'name' => $name] : null,
        ];
    }
}
