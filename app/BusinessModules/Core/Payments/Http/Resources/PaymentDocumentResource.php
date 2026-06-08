<?php

namespace App\BusinessModules\Core\Payments\Http\Resources;

use App\BusinessModules\Core\Payments\Enums\InvoiceType;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Services\PaymentBudgetLimitService;
use App\BusinessModules\Features\Procurement\Services\ProcurementChainService;
use App\Http\Resources\ModelJsonResource;
use Illuminate\Http\Request;

/**
 * API Resource для платежного документа
 */
class PaymentDocumentResource extends ModelJsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $document = $this->typedResource(PaymentDocument::class);

        // Владелец организации может отменять документы в любом статусе
        $canBeCancelled = $document->canBeCancelled();
        $user = $request->user();
        if ($user && !$canBeCancelled) {
            // Если по статусу нельзя отменить, но пользователь владелец - разрешаем
            $canBeCancelled = $user->isOrganizationOwner($document->organization_id);
        }
        $hasProcurementChain = $this->hasProcurementChain($document);
        $procurementChainSummary = $hasProcurementChain
            ? app(ProcurementChainService::class)->forPaymentDocument($document, $user)->compact()->toArray()
            : null;

        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'project_id' => $this->project_id,
            'budget_article_id' => $document->budgetArticle?->uuid,
            'budget_article_name' => $document->budgetArticle?->name,
            'responsibility_center_id' => $document->responsibilityCenter?->uuid,
            'responsibility_center_name' => $document->responsibilityCenter?->name,
            'document_type' => $this->document_type->value,
            'document_type_label' => $this->document_type->label(),
            'document_number' => $this->document_number,
            'document_date' => $this->document_date?->format('Y-m-d'),
            'payer_organization_id' => $this->payer_organization_id,
            'payer_contractor_id' => $this->payer_contractor_id,
            'payee_organization_id' => $this->payee_organization_id,
            'payee_contractor_id' => $this->payee_contractor_id,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'vat_amount' => $this->vat_amount ? (float) $this->vat_amount : null,
            'vat_rate' => $this->vat_rate ? (float) $this->vat_rate : null,
            'amount_without_vat' => $this->amount_without_vat ? (float) $this->amount_without_vat : null,
            'paid_amount' => (float) $this->paid_amount,
            'remaining_amount' => $this->remaining_amount ? (float) $this->remaining_amount : null,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'workflow_stage' => $this->workflow_stage,
            'due_date' => $this->due_date?->format('Y-m-d'),
            'payment_terms_days' => $this->payment_terms_days,
            'description' => $this->description,
            'payment_purpose' => $this->payment_purpose,
            'notes' => $this->notes,
            'formatted_amount' => $this->formatted_amount,
            'payment_percentage' => $document->getPaymentPercentage(),
            'days_until_due' => $document->getDaysUntilDue(),
            'is_overdue' => $document->isOverdue(),
            'can_be_paid' => $document->canBePaid(),
            'can_be_cancelled' => $canBeCancelled,
            'can_be_edited' => $document->canBeEdited(),
            'requires_approval' => $document->requiresApproval(),
            'budget_limit_check' => app(PaymentBudgetLimitService::class)->check($document, $user),
            'site_requests' => $this->whenLoaded('siteRequests', fn() => $this->siteRequests->map(fn($request) => [
                'id' => $request->id,
                'title' => $request->title,
                'request_type' => $request->request_type->value,
                'request_type_label' => $request->request_type->label(),
                'status' => $request->status->value,
                'status_label' => $request->status->label(),
                'pivot_amount' => $request->pivot->amount ? (float) $request->pivot->amount : null,
            ])),
            'site_requests_count' => $this->when($document->relationLoaded('siteRequests'), fn() => $document->siteRequests->count()),
            'payer_name' => $document->getPayerName(),
            'payee_name' => $document->getPayeeName(),
            'procurement_chain_summary' => $procurementChainSummary,
            'procurement_chain_href' => $hasProcurementChain
                ? '/procurement/chains/payment-documents/'.$document->id
                : null,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }

    private function hasProcurementChain(PaymentDocument $document): bool
    {
        $metadata = is_array($document->metadata) ? $document->metadata : [];

        if (isset($metadata['purchase_order_id'])) {
            return true;
        }

        return $document->invoice_type === InvoiceType::MATERIAL_PURCHASE;
    }
}
