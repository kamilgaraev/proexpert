<?php

namespace App\Http\Resources\Api\V1\Landing\Report;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConsolidatedCompletedWorkResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization' => [
                'id' => $this->organization_id,
                'name' => $this->organization->name ?? null,
            ],
            'project' => [
                'id' => $this->project_id,
                'name' => $this->project->name ?? null,
            ],
            'contract' => [
                'id' => $this->contract_id,
                'number' => $this->contract->number ?? null,
            ],
            'work_type' => [
                'id' => $this->work_type_id,
                'name' => $this->workType->name ?? null,
            ],
            'quantity' => (float) $this->quantity,
            'price' => (float) $this->price,
            'total_amount' => (float) $this->total_amount,
            'completion_date' => $this->completion_date?->format('Y-m-d'),
            'status' => $this->status,
        ];
    }
} 