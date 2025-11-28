<?php

namespace App\BusinessModules\Core\Payments\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API Resource для платежного документа
 */
class PaymentDocumentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'project_id' => $this->project_id,
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
            'payment_percentage' => $this->getPaymentPercentage(),
            'days_until_due' => $this->getDaysUntilDue(),
            'is_overdue' => $this->isOverdue(),
            'can_be_paid' => $this->canBePaid(),
            'can_be_cancelled' => $this->canBeCancelled(),
            'can_be_edited' => $this->canBeEdited(),
            'requires_approval' => $this->requiresApproval(),
            'site_requests' => $this->whenLoaded('siteRequests', fn() => $this->siteRequests->map(fn($request) => [
                'id' => $request->id,
                'title' => $request->title,
                'request_type' => $request->request_type->value,
                'request_type_label' => $request->request_type->label(),
                'status' => $request->status->value,
                'status_label' => $request->status->label(),
                'pivot_amount' => $request->pivot->amount ? (float) $request->pivot->amount : null,
            ])),
            'site_requests_count' => $this->when($this->relationLoaded('siteRequests'), fn() => $this->siteRequests->count()),
            'payer_name' => $this->getPayerName(),
            'payee_name' => $this->getPayeeName(),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}

