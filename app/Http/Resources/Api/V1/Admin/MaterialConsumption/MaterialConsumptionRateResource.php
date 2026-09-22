<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\MaterialConsumption;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\MaterialConsumptionRate */
final class MaterialConsumptionRateResource extends JsonResource
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
            'material_id' => $this->material_id,
            'work_type_id' => $this->work_type_id,
            'estimate_item_id' => $this->estimate_item_id,
            'work_unit_id' => $this->work_unit_id,
            'material_unit_id' => $this->material_unit_id,
            'quantity_per_work_unit' => (string) $this->quantity_per_work_unit,
            'version_number' => $this->version_number,
            'status' => $this->status,
            'basis_kind' => $this->basis_kind,
            'basis_text' => $this->basis_text,
            'basis_document_id' => $this->basis_document_id,
            'effective_from' => optional($this->effective_from)->toDateString(),
            'effective_to' => optional($this->effective_to)->toDateString(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'approved_by_user_id' => $this->approved_by_user_id,
        ];
    }
}
