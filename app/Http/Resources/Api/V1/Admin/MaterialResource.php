<?php

namespace App\Http\Resources\Api\V1\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MaterialResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'category' => $this->category,
            'default_price' => $this->default_price,
            'is_active' => $this->is_active,
            'additional_properties' => $this->additional_properties,
            
            // Поля для бухгалтерской интеграции
            'external_code' => $this->external_code,
            'sbis_nomenclature_code' => $this->sbis_nomenclature_code,
            'sbis_unit_code' => $this->sbis_unit_code,
            'consumption_rates' => $this->consumption_rates,
            'accounting_data' => $this->accounting_data,
            'use_in_accounting_reports' => $this->use_in_accounting_reports,
            'accounting_account' => $this->accounting_account,
            
            // Вложенные ресурсы
            'measurement_unit' => new MeasurementUnitResource($this->whenLoaded('measurementUnit')),
            'consumption_rates_with_work_types' => $this->when($request->has('include_consumption_rates'), function () {
                return $this->getConsumptionRatesWithWorkTypes();
            }),
            
            'organization_id' => $this->organization_id,
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at->format('Y-m-d H:i:s'),
        ];
    }
} 