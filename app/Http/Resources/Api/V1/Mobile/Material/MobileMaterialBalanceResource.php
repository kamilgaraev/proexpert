<?php

namespace App\Http\Resources\Api\V1\Mobile\Material;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MobileMaterialBalanceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // $this->resource - это один элемент из коллекции, 
        // которую вернул MaterialService::getMaterialBalancesForProject()
        // Этот метод возвращает коллекцию массивов, поэтому доступ через $this->resource['key']
        return [
            'material_id' => $this->resource['material_id'] ?? null,
            'material_name' => $this->resource['material_name'] ?? null,
            'measurement_unit_id' => $this->resource['measurement_unit_id'] ?? null,
            'measurement_unit_symbol' => $this->resource['measurement_unit_symbol'] ?? null,
            'current_balance' => isset($this->resource['current_balance']) ? (float) $this->resource['current_balance'] : 0.0,
        ];
    }
} 