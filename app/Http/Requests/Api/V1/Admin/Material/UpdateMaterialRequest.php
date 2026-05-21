<?php

namespace App\Http\Requests\Api\V1\Admin\Material;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;
use App\Models\Role;
use App\Models\Material; // РРјРїРѕСЂС‚РёСЂСѓРµРј РјРѕРґРµР»СЊ Material
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class UpdateMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Р”РѕСЃС‚СѓРї Рє РєРѕРЅС‚СЂРѕР»Р»РµСЂСѓ СѓР¶Рµ РїСЂРѕРІРµСЂРµРЅ middleware СЃС‚РµРєРѕРј Р°РІС‚РѕСЂРёР·Р°С†РёРё Р°РґРјРёРЅРєРё
        return Auth::check(); 
    }

    public function rules(): array
    {
        $organizationId = $this->getOrganizationId();

        /** @var Material|string|null $material */
        $material = $this->route('material'); // РњРѕР¶РµС‚ Р±С‹С‚СЊ РјРѕРґРµР»СЊСЋ РёР»Рё ID

        if ($material && !($material instanceof Material)) {
            $material = Material::find($material);
        }

        $materialId = $material?->id; // ID С‚РµРєСѓС‰РµРіРѕ РјР°С‚РµСЂРёР°Р»Р°

        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('materials', 'name')
                    ->where(function ($query) use ($organizationId) {
                        return $query->where('organization_id', $organizationId)
                                    ->whereNull('deleted_at');
                    })
                    ->ignore($materialId), // РРіРЅРѕСЂРёСЂСѓРµРј С‚РµРєСѓС‰РёР№ РјР°С‚РµСЂРёР°Р»
            ],
            'code' => 'sometimes|nullable|string|max:50',
            'measurement_unit_id' => [
                'sometimes',
                'required',
                'integer',
                $this->measurementUnitExistsRule($organizationId),
            ],
            'description' => 'sometimes|nullable|string|max:1000',
            'category' => 'sometimes|nullable|string|max:100',
            'default_price' => 'sometimes|nullable|numeric|min:0',
            'additional_properties' => 'sometimes|nullable|array',
            'is_active' => 'sometimes|boolean',
            
            // РџРѕР»СЏ РґР»СЏ Р±СѓС…РіР°Р»С‚РµСЂСЃРєРѕР№ РёРЅС‚РµРіСЂР°С†РёРё
            'external_code' => [
                'sometimes',
                'nullable', 
                'string', 
                'max:100',
                Rule::unique('materials', 'external_code')
                    ->where(function ($query) use ($organizationId) {
                        return $query->where('organization_id', $organizationId)
                                    ->whereNull('deleted_at');
                    })
                    ->ignore($materialId), // РРіРЅРѕСЂРёСЂСѓРµРј С‚РµРєСѓС‰РёР№ РјР°С‚РµСЂРёР°Р»
            ],
            'sbis_nomenclature_code' => 'sometimes|nullable|string|max:100',
            'sbis_unit_code' => 'sometimes|nullable|string|max:100',
            'consumption_rates' => 'sometimes|nullable|array',
            'consumption_rates.*' => 'numeric|min:0',
            'accounting_data' => 'sometimes|nullable|array',
            'use_in_accounting_reports' => 'sometimes|nullable|boolean',
            'accounting_account' => 'sometimes|nullable|string|max:50',
        ];
    }

    private function getOrganizationId(): ?int
    {
        $organizationId = $this->attributes->get('current_organization_id')
            ?? $this->user()?->current_organization_id;

        return $organizationId ? (int) $organizationId : null;
    }

    private function measurementUnitExistsRule(?int $organizationId)
    {
        return Rule::exists('measurement_units', 'id')
            ->where(function ($query) use ($organizationId): void {
                $query->whereNull('deleted_at')
                    ->where(function ($scope) use ($organizationId): void {
                        $scope->where('is_system', true);

                        if ($organizationId !== null) {
                            $scope->orWhere('organization_id', $organizationId);
                        }
                    });
            });
    }

    /**
     * РџРѕР»СѓС‡РёС‚СЊ РїРѕР»СЊР·РѕРІР°С‚РµР»СЊСЃРєРёРµ СЃРѕРѕР±С‰РµРЅРёСЏ РѕР± РѕС€РёР±РєР°С… РґР»СЏ РїСЂР°РІРёР» РїСЂРѕРІРµСЂРєРё.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'РќР°Р·РІР°РЅРёРµ РјР°С‚РµСЂРёР°Р»Р° РѕР±СЏР·Р°С‚РµР»СЊРЅРѕ РґР»СЏ Р·Р°РїРѕР»РЅРµРЅРёСЏ.',
            'name.unique' => 'РњР°С‚РµСЂРёР°Р» СЃ С‚Р°РєРёРј РЅР°Р·РІР°РЅРёРµРј СѓР¶Рµ СЃСѓС‰РµСЃС‚РІСѓРµС‚ РІ РІР°С€РµР№ РѕСЂРіР°РЅРёР·Р°С†РёРё.',
            'measurement_unit_id.required' => 'РќРµРѕР±С…РѕРґРёРјРѕ СѓРєР°Р·Р°С‚СЊ РµРґРёРЅРёС†Сѓ РёР·РјРµСЂРµРЅРёСЏ.',
            'measurement_unit_id.exists' => 'Р’С‹Р±СЂР°РЅРЅР°СЏ РµРґРёРЅРёС†Р° РёР·РјРµСЂРµРЅРёСЏ РЅРµ СЃСѓС‰РµСЃС‚РІСѓРµС‚.',
            'external_code.unique' => 'РњР°С‚РµСЂРёР°Р» СЃ С‚Р°РєРёРј РІРЅРµС€РЅРёРј РєРѕРґРѕРј СѓР¶Рµ СЃСѓС‰РµСЃС‚РІСѓРµС‚ РІ РІР°С€РµР№ РѕСЂРіР°РЅРёР·Р°С†РёРё.',
            'default_price.min' => 'Р¦РµРЅР° РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ РЅРµ РјРѕР¶РµС‚ Р±С‹С‚СЊ РѕС‚СЂРёС†Р°С‚РµР»СЊРЅРѕР№.',
            'consumption_rates.*.min' => 'РќРѕСЂРјР° СЃРїРёСЃР°РЅРёСЏ РЅРµ РјРѕР¶РµС‚ Р±С‹С‚СЊ РѕС‚СЂРёС†Р°С‚РµР»СЊРЅРѕР№.',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        $errors = (new ValidationException($validator))->errors();

        throw new HttpResponseException(
            \App\Http\Responses\AdminResponse::fromPayload([
                'success' => false,
                'message' => 'Р”Р°РЅРЅС‹Рµ РЅРµ РїСЂРѕС€Р»Рё РІР°Р»РёРґР°С†РёСЋ.',
                'errors' => $errors,
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY)
        );
    }
}
