<?php

namespace App\Http\Resources\Api\V1\Admin\Contract\Agreement;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplementaryAgreementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'contract_id' => $this->contract_id,
            'number' => $this->number,
            'agreement_date' => $this->agreement_date, // предполагается каст модели
            'change_amount' => (float) ($this->change_amount ?? 0),
            'subject_changes' => $this->subject_changes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
} 