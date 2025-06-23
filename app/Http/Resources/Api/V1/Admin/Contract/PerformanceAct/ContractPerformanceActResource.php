<?php

namespace App\Http\Resources\Api\V1\Admin\Contract\PerformanceAct;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\Api\V1\Admin\Contract\ContractMiniResource; // Для отображения связанного контракта

class ContractPerformanceActResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'contract_id' => $this->contract_id,
            // 'contract' => new ContractMiniResource($this->whenLoaded('contract')), // Если нужно будет загружать детали контракта
            'act_document_number' => $this->act_document_number,
            'act_date' => $this->act_date, // Предполагается, что в модели кастуется в Y-m-d
            'amount' => (float) $this->amount,
            'description' => $this->description,
            'is_approved' => (bool) $this->is_approved,
            'approval_date' => $this->approval_date,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
} 