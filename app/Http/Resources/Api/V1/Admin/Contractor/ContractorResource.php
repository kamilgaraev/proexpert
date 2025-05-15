<?php

namespace App\Http\Resources\Api\V1\Admin\Contractor;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
// Если будем использовать ContractMiniResource, нужно будет его импортировать
// use App\Http\Resources\Api\V1\Admin\Contract\ContractMiniResource;

class ContractorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'name' => $this->name,
            'contact_person' => $this->contact_person,
            'phone' => $this->phone,
            'email' => $this->email,
            'legal_address' => $this->legal_address,
            'inn' => $this->inn,
            'kpp' => $this->kpp,
            'bank_details' => $this->bank_details,
            'notes' => $this->notes,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
            // 'contracts' => ContractMiniResource::collection($this->whenLoaded('contracts')),
            // 'contracts_count' => $this->when(isset($this->contracts_count), $this->contracts_count),
        ];
    }
} 