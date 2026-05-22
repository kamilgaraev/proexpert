<?php

namespace App\BusinessModules\Features\Procurement\Http\Resources;

use App\Models\Contract;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseContractResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        assert($this->resource instanceof Contract);

        $contract = $this->resource;

        return [
            'id' => $contract->id,
            'organization_id' => $contract->organization_id,
            'project_id' => $contract->project_id,
            'supplier_id' => $contract->supplier_id,
            'contract_category' => $contract->contract_category,
            'number' => $contract->number,
            'date' => $contract->date->format('Y-m-d'),
            'subject' => $contract->subject,
            'work_type_category' => $contract->work_type_category?->value,
            'total_amount' => (float) $contract->total_amount,
            'status' => $contract->status->value,
            'start_date' => $contract->start_date?->format('Y-m-d'),
            'end_date' => $contract->end_date?->format('Y-m-d'),
            'notes' => $contract->notes,
            'is_procurement_contract' => $contract->isProcurementContract(),
            'supplier' => $this->whenLoaded('supplier', fn() => [
                'id' => $contract->supplier->id,
                'name' => $contract->supplier->name,
                'inn' => $contract->supplier->inn,
            ]),
            'project' => $this->whenLoaded('project', fn() => $contract->project ? [
                'id' => $contract->project->id,
                'name' => $contract->project->name,
            ] : null),
            'created_at' => $contract->created_at->toIso8601String(),
            'updated_at' => $contract->updated_at->toIso8601String(),
        ];
    }
}

