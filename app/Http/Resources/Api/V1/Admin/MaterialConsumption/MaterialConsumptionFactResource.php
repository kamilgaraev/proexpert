<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\MaterialConsumption;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\MaterialConsumptionFact */
final class MaterialConsumptionFactResource extends JsonResource
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
            'completed_work_id' => $this->completed_work_id,
            'material_id' => $this->material_id,
            'rate_id' => $this->rate_id,
            'kind' => $this->kind,
            'quantity' => (string) $this->quantity,
            'unit_id' => $this->unit_id,
            'converted_quantity' => (string) $this->converted_quantity,
            'conversion_basis' => $this->conversion_basis,
            'warehouse_movement_id' => $this->warehouse_movement_id,
            'batch_number' => $this->batch_number,
            'quality_document_id' => $this->quality_document_id,
            'site_remainder_quantity' => $this->site_remainder_quantity !== null ? (string) $this->site_remainder_quantity : null,
            'occurred_on' => optional($this->occurred_on)->toDateString(),
            'deviation_reason' => $this->deviation_reason,
            'agreed_by_user_id' => $this->agreed_by_user_id,
            'agreed_at' => $this->agreed_at?->toIso8601String(),
            'operation_key' => $this->operation_key,
        ];
    }
}
