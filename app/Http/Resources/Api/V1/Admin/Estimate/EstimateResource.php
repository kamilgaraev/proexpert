<?php

namespace App\Http\Resources\Api\V1\Admin\Estimate;

use App\BusinessModules\Features\BudgetEstimates\Services\Integration\EstimateCoverageService;
use App\Models\Estimate;
use Illuminate\Http\Resources\Json\JsonResource;

class EstimateResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var Estimate $estimate */
        $estimate = $this->resource;

        $coverage = app(EstimateCoverageService::class)->getCoverageForEstimate($estimate);
        $primaryContract = $coverage['primary_contract']['contract'] ?? null;

        return [
            'id' => $estimate->id,
            'organization_id' => $estimate->organization_id,
            'project_id' => $estimate->project_id,
            'contract_id' => $estimate->contract_id,
            'number' => $estimate->number,
            'name' => $estimate->name,
            'description' => $estimate->description,
            'type' => $estimate->type,
            'status' => $estimate->status,
            'version' => $estimate->version,
            'parent_estimate_id' => $estimate->parent_estimate_id,
            'estimate_date' => $estimate->estimate_date?->format('Y-m-d'),
            'base_price_date' => $estimate->base_price_date?->format('Y-m-d'),
            'total_direct_costs' => (float) $estimate->total_direct_costs,
            'total_overhead_costs' => (float) $estimate->total_overhead_costs,
            'total_estimated_profit' => (float) $estimate->total_estimated_profit,
            'total_amount' => (float) $estimate->total_amount,
            'total_amount_with_vat' => (float) $estimate->total_amount_with_vat,
            'vat_rate' => (float) $estimate->vat_rate,
            'overhead_rate' => (float) $estimate->overhead_rate,
            'profit_rate' => (float) $estimate->profit_rate,
            'calculation_method' => $estimate->calculation_method,
            'approved_at' => $estimate->approved_at?->toISOString(),
            'approved_by_user_id' => $estimate->approved_by_user_id,
            'metadata' => $estimate->metadata,
            'import_diagnostics' => $estimate->import_diagnostics,
            'created_at' => $estimate->created_at?->toISOString(),
            'updated_at' => $estimate->updated_at?->toISOString(),
            
            'project' => $this->whenLoaded('project'),
            'contract' => $primaryContract,
            'coverage' => new EstimateCoverageResource($coverage),
            'approved_by' => $this->whenLoaded('approvedBy'),
            'sections' => EstimateSectionResource::collection($this->whenLoaded('sections')),
            'items' => EstimateItemResource::collection($this->whenLoaded('items')),

            'totals' => [
                'base' => [
                    'direct_costs' => (float) $estimate->total_base_direct_costs,
                    'materials' => (float) $estimate->total_base_materials_cost,
                    'machinery' => (float) $estimate->total_base_machinery_cost,
                    'labor' => (float) $estimate->total_base_labor_cost,
                    'overhead_amount' => (float) $estimate->total_base_overhead_amount,
                    'profit_amount' => (float) $estimate->total_base_profit_amount,
                ],
                'current' => [
                    'direct_costs' => (float) $estimate->total_direct_costs,
                    'overhead_rate' => (float) $estimate->overhead_rate,
                    'overhead_amount' => (float) $estimate->total_overhead_costs,
                    'profit_rate' => (float) $estimate->profit_rate,
                    'profit_amount' => (float) $estimate->total_estimated_profit,
                    'total_without_vat' => (float) $estimate->total_amount,
                    'vat_rate' => (float) $estimate->vat_rate,
                    'vat_amount' => (float) ($estimate->total_amount_with_vat - $estimate->total_amount),
                    'total_with_vat' => (float) $estimate->total_amount_with_vat,
                ],
            ],
        ];
    }
}

