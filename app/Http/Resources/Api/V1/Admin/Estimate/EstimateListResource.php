<?php

namespace App\Http\Resources\Api\V1\Admin\Estimate;

use App\BusinessModules\Features\BudgetEstimates\Services\Integration\EstimateCoverageService;
use App\Models\Estimate;
use Illuminate\Http\Resources\Json\JsonResource;

class EstimateListResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var Estimate $estimate */
        $estimate = $this->resource;

        $coverage = app(EstimateCoverageService::class)->getCoverageForEstimate($estimate);
        $primaryContract = $coverage['primary_contract']['contract'] ?? null;

        return [
            'id' => $estimate->id,
            'number' => $estimate->number,
            'name' => $estimate->name,
            'type' => $estimate->type,
            'status' => $estimate->status,
            'version' => $estimate->version,
            'estimate_date' => $estimate->estimate_date?->format('Y-m-d'),
            'total_amount' => (float) $estimate->total_amount,
            'total_amount_with_vat' => (float) $estimate->total_amount_with_vat,
            'project' => $this->whenLoaded('project', function () use ($estimate) {
                return [
                    'id' => $estimate->project->id,
                    'name' => $estimate->project->name,
                ];
            }),
            'contract' => $primaryContract,
            'coverage_status' => $coverage['coverage_status'],
            'coverage' => new EstimateCoverageResource($coverage),
            'created_at' => $estimate->created_at?->toISOString(),
            'updated_at' => $estimate->updated_at?->toISOString(),
        ];
    }
}

