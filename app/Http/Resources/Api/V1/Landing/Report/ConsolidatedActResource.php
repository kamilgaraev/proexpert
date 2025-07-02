<?php

namespace App\Http\Resources\Api\V1\Landing\Report;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConsolidatedActResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization' => [
                'id' => $this->organization_id,
                'name' => $this->organization->name ?? null,
            ],
            'contract_id' => $this->contract_id,
            'act_document_number' => $this->act_document_number,
            'act_date' => $this->act_date?->format('Y-m-d'),
            'amount' => (float) ($this->amount ?? 0),
            'is_approved' => (bool) $this->is_approved,
        ];
    }
} 