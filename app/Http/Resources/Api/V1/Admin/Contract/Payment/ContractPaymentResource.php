<?php

namespace App\Http\Resources\Api\V1\Admin\Contract\Payment;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContractPaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'contract_id' => $this->contract_id,
            // 'contract' => new ContractMiniResource($this->whenLoaded('contract')), // Если нужно будет загружать детали контракта
            'payment_date' => $this->payment_date, // Предполагается, что в модели кастуется в Y-m-d
            'amount' => (float) ($this->amount ?? 0),
            'payment_type' => $this->payment_type->value, // Enum
            'payment_type_label' => $this->payment_type->name, // Для отображения
            'reference_document_number' => $this->reference_document_number,
            'description' => $this->description,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
} 