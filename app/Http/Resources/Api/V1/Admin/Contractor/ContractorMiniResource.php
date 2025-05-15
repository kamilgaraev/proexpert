<?php

namespace App\Http\Resources\Api\V1\Admin\Contractor;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContractorMiniResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            // Можно добавить ИНН или другие ключевые поля, если нужно для отображения
            'inn' => $this->when(isset($this->inn), $this->inn),
        ];
    }
} 