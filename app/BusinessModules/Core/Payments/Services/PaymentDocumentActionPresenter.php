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
        $definition = match ($document->status) {
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
            PaymentDocumentStatus::APPROVED,
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

        if ($definition !== null && $remainingAmount > 0.0001) {
            [$key, $href, $permission] = $definition;
            $isEnabled = $user?->can($permission, ['organization_id' => (int) $document->organization_id]) ?? false;
            $primaryAction = [
                'key' => $key,
                'label' => trans_message("payments.actions.{$key}"),
                'href' => $href,
                'method' => 'POST',
                'required_permission' => $permission,
                'permission' => $permission,
                'is_enabled' => $isEnabled,
                'disabled' => ! $isEnabled,
                'disabled_reason' => $isEnabled ? null : trans_message('payments.blockers.permission_required'),
                'scope' => 'payment_document',
                'priority' => 'primary',
            ];
        }

        return [
            'primary_action' => $primaryAction,
            'secondary_actions' => [],
            'menu_actions' => [],
            'blockers' => $primaryAction && ! $primaryAction['is_enabled'] ? [[
                'key' => 'permission_required',
                'message' => trans_message('payments.blockers.permission_required'),
                'severity' => 'warning',
                'entity_type' => 'payment_document',
                'entity_id' => $document->id,
                'action' => $primaryAction,
            ]] : [],
        ];
    }
}
