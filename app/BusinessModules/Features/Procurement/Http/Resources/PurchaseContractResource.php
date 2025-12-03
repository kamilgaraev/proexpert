<?php

namespace App\BusinessModules\Features\Procurement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseContractResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'project_id' => $this->project_id,
            'supplier_id' => $this->supplier_id,
            'contract_category' => $this->contract_category,
            'number' => $this->number,
            'date' => $this->date->format('Y-m-d'),
            'subject' => $this->subject,
            'work_type_category' => $this->work_type_category?->value,
            'total_amount' => (float) $this->total_amount,
            'status' => $this->status->value,
            'start_date' => $this->start_date?->format('Y-m-d'),
            'end_date' => $this->end_date?->format('Y-m-d'),
            'notes' => $this->notes,
            'is_procurement_contract' => $this->isProcurementContract(),
            'supplier' => $this->whenLoaded('supplier', fn() => [
                'id' => $this->supplier->id,
                'name' => $this->supplier->name,
                'inn' => $this->supplier->inn,
            ]),
            'project' => $this->whenLoaded('project', fn() => $this->project ? [
                'id' => $this->project->id,
                'name' => $this->project->name,
            ] : null),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}

