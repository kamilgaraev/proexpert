<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\ActReport;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ContractPeriodCertificate */
final class ContractPeriodCertificateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'contract_id' => $this->contract_id,
            'project_id' => $this->project_id,
            'period_start' => optional($this->period_start)->toDateString(),
            'period_end' => optional($this->period_end)->toDateString(),
            'number' => $this->number,
            'version_number' => $this->version_number,
            'status' => $this->status,
            'calculation_version' => $this->calculation_version,
            'source_act_ids' => $this->source_act_ids,
            'composition' => $this->composition,
            'snapshot' => $this->snapshot,
            'totals' => $this->totals,
            'document_date' => optional($this->document_date)->toDateString(),
            'performed_at' => optional($this->performed_at)->toDateString(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'approved_by_user_id' => $this->approved_by_user_id,
            'signed_at' => $this->signed_at?->toIso8601String(),
            'signed_by_user_id' => $this->signed_by_user_id,
            'signed_file_id' => $this->signed_file_id,
            'has_annulled_acts' => (bool) $this->has_annulled_acts,
            'created_by_user_id' => $this->created_by_user_id,
            'is_frozen' => $this->isFrozen(),
        ];
    }
}
