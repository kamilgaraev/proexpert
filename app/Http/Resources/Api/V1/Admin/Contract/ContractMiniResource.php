<?php

namespace App\Http\Resources\Api\V1\Admin\Contract;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContractMiniResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'date' => $this->date,
            'total_amount' => (float) ($this->total_amount ?? 0),
            'status' => $this->status->value,
            'status_label' => $this->status->name,
        ];
    }
} 