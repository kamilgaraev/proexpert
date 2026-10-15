<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Services;

use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\Models\User;

final class PaymentDocumentActionPresenter
{
    public function present(PaymentDocument $document, ?User $user): array
    {
        $needsFinancialRoute = $document->requiresActInvoiceFinancialApproval()
            && ((in_array($document->status, [PaymentDocumentStatus::SUBMITTED, PaymentDocumentStatus::PENDING_APPROVAL], true)
                    && ! $document->approvals()->exists())
                || $document->canRecoverActInvoiceFinancialApproval());
        $definition = $needsFinancialRoute ? [
            'submit_payment_document',
            "/api/v1/admin/payments/documents/{$document->id}/submit",
            'payments.invoice.issue',
        ] : match ($document->status) {
            PaymentDocumentStatus::DRAFT => [
                'submit_payment_document',
                "/api/v1/admin/payments/documents/{$document->id}/submit",
                'payments.invoice.issue',
            ],
            PaymentDocumentStatus::SUBMITTED,
            PaymentDocumentStatus::PENDING_APPROVAL => [
                'approve_payment_document',
                "/api/v1/admin/payments/approvals/documents/{$document->id}/approve",
                'payments.transaction.approve',
            ],
            PaymentDocumentStatus::APPROVED => $document->requiresActInvoiceFinancialApproval() ? [
                'schedule_payment',
                "/api/v1/admin/payments/documents/{$document->id}/schedule",
                'payments.schedule.create',
            ] : [
                'register_payment',
                "/api/v1/admin/payments/documents/{$document->id}/register-payment",
                'payments.transaction.register',
            ],
            PaymentDocumentStatus::SCHEDULED,
            PaymentDocumentStatus::PARTIALLY_PAID => [
                'register_payment',
                "/api/v1/admin/payments/documents/{$document->id}/register-payment",
                'payments.transaction.register',
            ],
            default => null,
        };

        $remainingAmount = $document->remaining_amount !== null
            ? (float) $document->remaining_amount
            : max((float) $document->amount - (float) $document->paid_amount, 0.0);
        $primaryAction = null;
        $blockerKey = 'permission_required';

        if ($definition !== null && $remainingAmount > 0.0001) {
            [$key, $href, $permission] = $definition;
            $hasPermission = $user !== null && app(PaymentDocumentAccessService::class)
                ->query((int) $document->organization_id, $user, $permission)->whereKey($document->id)->exists();
            $waitingForApprovalLevel = $key === 'approve_payment_document' && $document->requiresActInvoiceFinancialApproval()
                && $user !== null && ! app(ApprovalWorkflowService::class)->canDecideCurrentApproval($document, $user);
            $isEnabled = $hasPermission && ! $waitingForApprovalLevel;
            if ($hasPermission && $waitingForApprovalLevel) {
                $blockerKey = 'approval_level_unavailable';
            }
            $primaryAction = [
                'key' => $key,
                'label' => $needsFinancialRoute
                    ? trans_message('payments.actions.submit_act_invoice_for_financial_approval')
                    : trans_message("payments.actions.{$key}"),
                'href' => $href,
                'method' => 'POST',
                'required_permission' => $permission,
                'permission' => $permission,
                'is_enabled' => $isEnabled,
                'disabled' => ! $isEnabled,
                'disabled_reason' => $isEnabled ? null : trans_message($hasPermission && $waitingForApprovalLevel
                    ? 'payments.validation.act_invoice_approval_level_unavailable' : 'payments.blockers.permission_required'),
                'scope' => 'payment_document',
                'priority' => 'primary',
            ];
        }

        return [
            'primary_action' => $primaryAction,
            'secondary_actions' => [],
            'menu_actions' => [],
            'blockers' => $primaryAction && ! $primaryAction['is_enabled'] ? [[
                'key' => $blockerKey,
                'message' => $primaryAction['disabled_reason'],
                'severity' => 'warning',
                'entity_type' => 'payment_document',
                'entity_id' => $document->id,
                'action' => $primaryAction,
            ]] : [],
        ];
    }
}
