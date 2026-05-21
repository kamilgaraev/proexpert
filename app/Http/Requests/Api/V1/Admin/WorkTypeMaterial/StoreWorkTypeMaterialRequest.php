<?php

namespace App\Http\Requests\Api\V1\Admin\WorkTypeMaterial;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use App\DTOs\WorkTypeMaterial\WorkTypeMaterialDTO;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StoreWorkTypeMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check(); // Р”РѕСЃС‚СѓРї Рє Р°РґРјРёРЅРєРµ РѕР±С‹С‡РЅРѕ РїСЂРѕРІРµСЂСЏРµС‚СЃСЏ middleware РіСЂСѓРїРїС‹
    }

    public function rules(): array
    {
        $organizationId = $this->attributes->get('current_organization_id') ?? $this->user()?->current_organization_id;

        return [
            'materials' => 'required|array|min:1',
            'materials.*.material_id' => [
                'required',
                'integer',
                Rule::exists('materials', 'id')->where(function ($query) use ($organizationId): void {
                    if ($organizationId) {
                        $query->where('organization_id', (int) $organizationId);
                    }
                }),
            ],
            'materials.*.default_quantity' => 'required|numeric|min:0',
            'materials.*.notes' => 'nullable|string|max:1000',
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

    /**
     * @return WorkTypeMaterialDTO[]
     */
    public function toDtos(): array
    {
        $dtos = [];
        foreach ($this->validated()['materials'] as $materialData) {
            $dtos[] = new WorkTypeMaterialDTO(
                material_id: $materialData['material_id'],
                default_quantity: (float)$materialData['default_quantity'],
                notes: $materialData['notes'] ?? null
                // organization_id Рё work_type_id Р±СѓРґСѓС‚ СѓСЃС‚Р°РЅРѕРІР»РµРЅС‹ РІ СЃРµСЂРІРёСЃРµ
            );
        }
        return $dtos;
    }
} 
