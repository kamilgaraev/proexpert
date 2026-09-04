<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Services;

use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\Models\User;
use Illuminate\Support\Facades\Log;

final class PaymentDocumentPresenter
{
    public function __construct(
        private readonly PaymentBudgetLimitService $budgetLimitService,
        private readonly PurchaseOrderContractRequirementService $contractRequirement,
        private readonly PaymentDocumentActionPresenter $actions,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function brief(PaymentDocument $document, ?User $user): array
    {
        $problemFlags = $this->buildProblemFlags($document);

        return [
            'id' => $document->id,
            'document_number' => $document->document_number,
            'document_type' => $document->document_type->value,
            'document_type_label' => $document->document_type->label(),
            'invoice_type' => $document->invoice_type?->value,
            'direction' => $document->direction?->value,
            'document_date' => $document->document_date->format('Y-m-d'),
            'due_date' => $document->due_date?->format('Y-m-d'),
            'status' => $document->status->value,
            'status_label' => $document->status->label(),
            'amount' => $document->amount,
            'paid_amount' => $document->paid_amount,
            'remaining_amount' => $document->remaining_amount,
            'currency' => $document->currency,
            'payer_name' => $document->getPayerName(),
            'payee_name' => $document->getPayeeName(),
            'project' => $document->project ? [
                'id' => $document->project->id,
                'name' => $document->project->name,
            ] : null,
            'budget_article_id' => $document->budgetArticle?->uuid,
            'budget_article_name' => $document->budgetArticle?->name,
            'responsibility_center_id' => $document->responsibilityCenter?->uuid,
            'responsibility_center_name' => $document->responsibilityCenter?->name,
            'is_overdue' => $document->isOverdue(),
            'days_until_due' => $document->getDaysUntilDue(),
            'payment_percentage' => $document->getPaymentPercentage(),
            'site_requests_count' => (int) ($document->site_requests_count ?? 0),
            'problem_flags' => $problemFlags,
            'workflow_summary' => $this->buildWorkflowSummary($document, $problemFlags, []),
            'budget_limit_check' => $this->budgetLimitService->check($document, $user),
            'can_be_cancelled' => $document->canBeCancelled(),
            'action_summary' => $this->actions->present($document, $user),
            'created_at' => $document->created_at->toDateTimeString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detailed(PaymentDocument $document, ?User $user): array
    {
        $basic = $this->brief($document, $user);
        $canApprove = false;

        if (in_array($document->status->value, ['submitted', 'pending_approval'], true) && $user) {
            $orgId = (int) $document->organization_id;
            $context = ['organization_id' => $orgId];

            $canApprove = $user->isSystemAdmin()
                || $user->isOrganizationOwner($orgId)
                || $user->can('payments.transaction.approve', $context)
                || $document->approvals()
                    ->where('status', 'pending')
                    ->where('approver_user_id', $user->id)
                    ->exists();
        }

        $contractId = null;
        if ($document->invoiceable_type === 'App\\Models\\Contract' && $document->invoiceable_id) {
            $contractId = $document->invoiceable_id;
        }

        $invoiceable = $this->formatInvoiceable($document, $contractId);
        if (isset($invoiceable['contract_id'])) {
            $contractId = $invoiceable['contract_id'];
            unset($invoiceable['contract_id']);
        }

        $relatedSiteRequests = $document->relationLoaded('siteRequests')
            ? $document->siteRequests->map(fn ($siteRequest): array => [
                'id' => $siteRequest->id,
                'title' => $siteRequest->title,
                'status' => is_object($siteRequest->status) ? $siteRequest->status->value : $siteRequest->status,
                'project_id' => $siteRequest->project_id,
                'payment_amount' => $siteRequest->pivot?->amount,
            ])->values()->all()
            : [];
        $problemFlags = $this->buildProblemFlags($document);
        $workflowSummary = $this->buildWorkflowSummary($document, $problemFlags, $relatedSiteRequests);

        return array_merge($basic, [
            'description' => $document->description,
            'payment_purpose' => $document->payment_purpose,
            'vat_rate' => $document->vat_rate,
            'vat_amount' => $document->vat_amount,
            'amount_without_vat' => $document->amount_without_vat,
            'bank_details' => [
                'account' => $document->bank_account,
                'bik' => $document->bank_bik,
                'correspondent_account' => $document->bank_correspondent_account,
                'bank_name' => $document->bank_name,
            ],
            'contract_id' => $contractId,
            'invoiceable' => $invoiceable === [] ? null : $invoiceable,
            'source' => $this->formatSourceReference($document),
            'attached_documents' => $document->attached_documents,
            'metadata' => $document->metadata,
            'notes' => $document->notes,
            'estimate_splits' => $document->relationLoaded('estimateSplits') ? $document->estimateSplits->map(fn ($split): array => [
                'id' => $split->id,
                'estimate_item_id' => $split->estimate_item_id,
                'quantity' => (float) $split->quantity,
                'unit_price_plan' => (float) $split->unit_price_plan,
                'unit_price_actual' => (float) $split->unit_price_actual,
                'amount' => (float) $split->amount,
                'percentage' => (float) $split->percentage,
                'price_deviation' => (float) $split->price_deviation,
                'estimate_item' => $split->estimateItem ? [
                    'id' => $split->estimateItem->id,
                    'title' => $split->estimateItem->name,
                    'unit_name' => $split->estimateItem->measurementUnit ? $split->estimateItem->measurementUnit->short_name : null,
                ] : null,
            ]) : [],
            'can_be_approved_by_current_user' => $canApprove,
            'recipient_is_registered' => $document->hasRegisteredRecipient(),
            'recipient_organization_id' => $document->recipient_organization_id,
            'recipient_viewed_at' => $document->recipient_viewed_at?->toDateTimeString(),
            'recipient_confirmed_at' => $document->recipient_confirmed_at?->toDateTimeString(),
            'recipient_confirmation_comment' => $document->recipient_confirmation_comment,
            'recipient_confirmed_by' => $document->recipientConfirmedBy ? [
                'id' => $document->recipientConfirmedBy->id,
                'name' => $document->recipientConfirmedBy->name,
            ] : null,
            'workflow' => [
                'workflow_stage' => $document->workflow_stage,
                'submitted_at' => $document->submitted_at?->toDateTimeString(),
                'approved_at' => $document->approved_at?->toDateTimeString(),
                'scheduled_at' => $document->scheduled_at?->toDateTimeString(),
                'paid_at' => $document->paid_at?->toDateTimeString(),
            ],
            'workflow_summary' => $workflowSummary,
            'problem_flags' => $problemFlags,
            'related_site_requests' => $relatedSiteRequests,
            'approvals_count' => $document->approvals?->count() ?? 0,
            'transactions_count' => $document->transactions?->count() ?? 0,
            'updated_at' => $document->updated_at->toDateTimeString(),
        ]);
    }

    /**
     * @param  array<int, PaymentDocument>|\Illuminate\Support\Collection<int, PaymentDocument>  $documents
     * @return array<int, array<string, mixed>>
     */
    public function collection(iterable $documents, ?User $user): array
    {
        $items = [];

        foreach ($documents as $document) {
            $items[] = $this->brief($document, $user);
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatInvoiceable(PaymentDocument $document, ?int $contractId): array
    {
        if (! $document->invoiceable_type || ! $document->invoiceable_id || str_contains($document->invoiceable_type, 'Payments\\Models\\Invoice')) {
            return [];
        }

        try {
            $invoiceableModel = $document->invoiceable;

            if (! $invoiceableModel) {
                return [];
            }

            $invoiceable = [
                'type' => $document->invoiceable_type,
                'id' => $document->invoiceable_id,
            ];

            if ($document->invoiceable_type === 'App\\Models\\Contract') {
                $invoiceable['number'] = $invoiceableModel->number ?? null;
                $invoiceable['subject'] = $invoiceableModel->subject ?? null;
                $invoiceable['contract_id'] = $document->invoiceable_id;
            }

            if ($document->invoiceable_type === 'App\\Models\\ContractPerformanceAct') {
                $invoiceable['number'] = $invoiceableModel->number ?? null;
                $invoiceable['act_date'] = $invoiceableModel->act_date?->format('Y-m-d') ?? null;
                if ($invoiceableModel->contract_id) {
                    $invoiceable['contract_id'] = $invoiceableModel->contract_id;
                }
            }

            return $invoiceable;
        } catch (\Error|\Exception $e) {
            Log::debug('payment_document.invoiceable_load_failed', [
                'document_id' => $document->id,
                'invoiceable_type' => $document->invoiceable_type,
                'invoiceable_id' => $document->invoiceable_id,
                'error' => $e->getMessage(),
            ]);

            return $contractId !== null ? ['contract_id' => $contractId] : [];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function formatSourceReference(PaymentDocument $document): ?array
    {
        if ($document->source_type === null || $document->source_type === '' || $document->source_id === null) {
            return null;
        }

        return [
            'type' => $document->source_type,
            'id' => $document->source_id,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function buildProblemFlags(PaymentDocument $document): array
    {
        if (in_array($document->status, [
            PaymentDocumentStatus::PAID,
            PaymentDocumentStatus::REJECTED,
            PaymentDocumentStatus::CANCELLED,
        ], true)) {
            return [];
        }

        $flags = [];

        if ($document->document_type->isOutgoing() && (! $document->bank_account || ! $document->bank_bik)) {
            $flags[] = 'missing_bank_details';
        }

        if (! $document->payee_contractor_id && ! $document->payee_organization_id) {
            $flags[] = 'missing_counterparty';
        }

        if ($document->status === PaymentDocumentStatus::DRAFT) {
            $flags[] = 'awaiting_submission';
        }

        if (in_array($document->status, [PaymentDocumentStatus::SUBMITTED, PaymentDocumentStatus::PENDING_APPROVAL], true)) {
            $flags[] = 'awaiting_approval';
        }

        if ($document->status === PaymentDocumentStatus::APPROVED) {
            $flags[] = 'awaiting_schedule';
        }

        if (in_array($document->status, [PaymentDocumentStatus::APPROVED, PaymentDocumentStatus::SCHEDULED], true)
            && (float) $document->remaining_amount > 0) {
            $flags[] = 'awaiting_payment';
        }

        if ($document->status === PaymentDocumentStatus::PARTIALLY_PAID) {
            $flags[] = 'partially_paid';
        }

        if ($document->isOverdue()) {
            $flags[] = 'overdue';
        }

        if ($document->relationLoaded('estimateSplits') && $document->estimateSplits->contains(fn ($split): bool => (float) $split->price_deviation > 0)) {
            $flags[] = 'has_estimate_deviation';
        }

        if (($contractBlocker = $this->contractRequirement->blocker($document)) !== null) {
            $flags[] = $contractBlocker;
        }

        return array_values(array_unique($flags));
    }

    /**
     * @param  array<int, string>  $problemFlags
     * @param  array<int, array<string, mixed>>  $relatedSiteRequests
     * @return array<string, mixed>
     */
    private function buildWorkflowSummary(PaymentDocument $document, array $problemFlags, array $relatedSiteRequests): array
    {
        $currentStage = match ($document->status) {
            PaymentDocumentStatus::DRAFT => 'draft',
            PaymentDocumentStatus::SUBMITTED, PaymentDocumentStatus::PENDING_APPROVAL => 'approval',
            PaymentDocumentStatus::APPROVED => 'approved',
            PaymentDocumentStatus::SCHEDULED => 'scheduled',
            PaymentDocumentStatus::PARTIALLY_PAID => 'partial_payment',
            PaymentDocumentStatus::PAID => 'paid',
            PaymentDocumentStatus::REJECTED => 'rejected',
            PaymentDocumentStatus::CANCELLED => 'cancelled',
            default => 'unknown',
        };

        $nextAction = match ($document->status) {
            PaymentDocumentStatus::DRAFT => 'submit',
            PaymentDocumentStatus::SUBMITTED, PaymentDocumentStatus::PENDING_APPROVAL => 'approve_or_reject',
            PaymentDocumentStatus::APPROVED => 'schedule_payment',
            PaymentDocumentStatus::SCHEDULED => 'register_payment',
            PaymentDocumentStatus::PARTIALLY_PAID => 'complete_payment',
            default => null,
        };

        return [
            'current_stage' => $currentStage,
            'next_action' => $nextAction,
            'is_blocked' => ! empty($problemFlags),
            'blockers' => $problemFlags,
            'related_site_requests' => $relatedSiteRequests,
            'available_actions' => array_values(array_filter([
                $this->contractRequirement->continuationAction($document),
            ])),
        ];
    }
}
