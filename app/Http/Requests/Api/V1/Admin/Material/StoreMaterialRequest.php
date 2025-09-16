<?php

namespace App\Http\Requests\Api\V1\Admin\Material;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;
use App\Models\Role;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class StoreMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Доступ к контроллеру уже проверен middleware стеком авторизации админки
        return Auth::check(); 
    }

    public function rules(): array
    {
        $organizationId = $this->get('current_organization_id');

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('materials', 'name')
                    ->where(function ($query) use ($organizationId) {
                        return $query->where('organization_id', $organizationId)
                                    ->whereNull('deleted_at');
                    }),
            ],
            'code' => 'nullable|string|max:50',
            'measurement_unit_id' => 'required|integer|exists:measurement_units,id', 
            'description' => 'nullable|string|max:1000',
            'category' => 'nullable|string|max:100',
            'default_price' => 'nullable|numeric|min:0',
            'additional_properties' => 'nullable|array',
            'is_active' => 'sometimes|boolean',
            
            // Поля для бухгалтерской интеграции
            'external_code' => [
                'nullable', 
                'string', 
                'max:100',
                Rule::unique('materials', 'external_code')
                    ->where(function ($query) use ($organizationId) {
                        return $query->where('organization_id', $organizationId)
                                    ->whereNull('deleted_at');
                    }),
            ],
            'sbis_nomenclature_code' => 'nullable|string|max:100',
            'sbis_unit_code' => 'nullable|string|max:100',
            'consumption_rates' => 'nullable|array',
            'consumption_rates.*' => 'numeric|min:0',
            'accounting_data' => 'nullable|array',
            'use_in_accounting_reports' => 'nullable|boolean',
            'accounting_account' => 'nullable|string|max:50',
        ];
    }

    /**
     * Получить пользовательские сообщения об ошибках для правил проверки.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Название материала обязательно для заполнения.',
            'name.unique' => 'Материал с таким названием уже существует в вашей организации.',
            'measurement_unit_id.required' => 'Необходимо указать единицу измерения.',
            'measurement_unit_id.exists' => 'Выбранная единица измерения не существует.',
            'external_code.unique' => 'Материал с таким внешним кодом уже существует в вашей организации.',
            'default_price.min' => 'Цена по умолчанию не может быть отрицательной.',
            'consumption_rates.*.min' => 'Норма списания не может быть отрицательной.',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        $errors = (new ValidationException($validator))->errors();

        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => 'Данные не прошли валидацию.',
                'errors' => $errors,
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY)
        );
    }
}