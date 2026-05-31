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
            'contractor_id' => $contract->contractor_id,
            'supplier_id' => $contract->supplier_id,
            'contract_category' => $contract->contract_category,
            'number' => $contract->number,
            'date' => $contract->date->format('Y-m-d'),
            'subject' => $contract->subject,
            'work_type_category' => $contract->work_type_category?->value,
            'total_amount' => (float) $contract->total_amount,
            'status' => $contract->status->value,
            'status_label' => $contract->status->label(),
            'start_date' => $contract->start_date?->format('Y-m-d'),
            'end_date' => $contract->end_date?->format('Y-m-d'),
            'notes' => $contract->notes,
            'is_procurement_contract' => $contract->isProcurementContract(),
            'supplier_display_name' => $this->supplierDisplayName($contract),
            'supplier_display_inn' => $this->supplierDisplayInn($contract),
            'supplier_display_source' => $this->supplierDisplaySource($contract),
            'supplier' => $this->whenLoaded('supplier', fn() => $contract->supplier ? [
                'id' => $contract->supplier->id,
                'name' => $contract->supplier->name,
                'inn' => $contract->supplier->inn,
            ] : null),
            'contractor' => $this->whenLoaded('contractor', fn() => $contract->contractor ? [
                'id' => $contract->contractor->id,
                'name' => $contract->contractor->name,
                'inn' => $contract->contractor->inn,
            ] : null),
            'project' => $this->whenLoaded('project', fn() => $contract->project ? [
                'id' => $contract->project->id,
                'name' => $contract->project->name,
            ] : null),
            'created_at' => $contract->created_at->toIso8601String(),
            'updated_at' => $contract->updated_at->toIso8601String(),
        ];
    }

    private function supplierDisplayName(Contract $contract): ?string
    {
        if ($contract->relationLoaded('supplier') && $contract->supplier) {
            return $contract->supplier->name;
        }

        if ($contract->relationLoaded('contractor') && $contract->contractor) {
            return $contract->contractor->name;
        }

        return null;
    }

    private function supplierDisplayInn(Contract $contract): ?string
    {
        if ($contract->relationLoaded('supplier') && $contract->supplier) {
            return $contract->supplier->inn;
        }

        if ($contract->relationLoaded('contractor') && $contract->contractor) {
            return $contract->contractor->inn;
        }

        return null;
    }

    private function supplierDisplaySource(Contract $contract): ?string
    {
        if ($contract->supplier_id !== null) {
            return 'supplier';
        }

        if ($contract->contractor_id !== null) {
            return 'contractor';
        }

        return null;
    }
}

