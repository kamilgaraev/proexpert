<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\MaterialConsumption;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\MaterialConsumptionStatement */
final class MaterialConsumptionStatementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'project_id' => $this->project_id,
            'contract_id' => $this->contract_id,
            'period_start' => optional($this->period_start)->toDateString(),
            'period_end' => optional($this->period_end)->toDateString(),
            'number' => $this->number,
            'version_number' => $this->version_number,
            'status' => $this->status,
            'calculation_version' => $this->calculation_version,
            'snapshot' => $this->snapshot,
            'totals' => $this->totals,
            'blockers' => $this->blockers,
            'is_ready' => (bool) $this->is_ready,
            'foreman_name' => $this->foreman_name,
            'document_date' => optional($this->document_date)->toDateString(),
            'performed_at' => optional($this->performed_at)->toDateString(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'approved_by_user_id' => $this->approved_by_user_id,
            'signed_at' => $this->signed_at?->toIso8601String(),
            'signed_by_user_id' => $this->signed_by_user_id,
            'signed_file_id' => $this->signed_file_id,
            'is_frozen' => $this->isFrozen(),
        ];
    }
}
