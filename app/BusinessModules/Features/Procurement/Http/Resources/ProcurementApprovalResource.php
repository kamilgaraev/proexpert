<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Http\Resources;

use App\BusinessModules\Features\Procurement\Models\ProcurementApproval;
use App\BusinessModules\Features\Procurement\Models\SupplierProposalDecision;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProcurementApproval */
class ProcurementApprovalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'approvable_type' => $this->approvable_type,
            'approvable_id' => $this->approvable_id,
            'reason_code' => $this->reason_code,
            'status' => $this->status->value,
            'requested_by' => $this->requested_by,
            'approved_by' => $this->approved_by,
            'rejected_by' => $this->rejected_by,
            'requested_at' => $this->requested_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'comment' => $this->comment,
            'context' => $this->context,
            'decision' => $this->whenLoaded('approvable', function () {
                if (!$this->approvable instanceof SupplierProposalDecision) {
                    return null;
                }

                return new SupplierProposalDecisionResource($this->approvable);
            }),
            'requested_user' => $this->whenLoaded('requestedBy', fn () => $this->requestedBy ? [
                'id' => $this->requestedBy->id,
                'name' => $this->requestedBy->name,
            ] : null),
            'approved_user' => $this->whenLoaded('approvedBy', fn () => $this->approvedBy ? [
                'id' => $this->approvedBy->id,
                'name' => $this->approvedBy->name,
            ] : null),
            'rejected_user' => $this->whenLoaded('rejectedBy', fn () => $this->rejectedBy ? [
                'id' => $this->rejectedBy->id,
                'name' => $this->rejectedBy->name,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
