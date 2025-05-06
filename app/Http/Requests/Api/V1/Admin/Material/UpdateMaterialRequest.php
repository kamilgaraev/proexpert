<?php

namespace App\Http\Requests\Api\V1\Admin\Material;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;
use App\Models\Role;
use App\Models\Material; // Импортируем модель Material
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class UpdateMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Доступ к контроллеру уже проверен middleware 'can:access-admin-panel'
        return Auth::check(); 
    }

    public function rules(): array
    {
        $organizationId = $this->attributes->get('organization_id');
        /** @var Material|null $material */
        $material = $this->route('material'); // Получаем модель из маршрута
        $materialId = $material?->id; // ID текущего материала

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
                    ->ignore($materialId), // Игнорируем текущий материал
            ],
            'measurement_unit_id' => 'sometimes|required|integer|exists:measurement_units,id',
            'category' => 'nullable|string|max:100',
            'is_active' => 'sometimes|boolean',
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