<?php

namespace App\Http\Requests\Api\V1\Admin\WorkTypeMaterial;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use App\DTOs\WorkTypeMaterial\WorkTypeMaterialDTO;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class StoreWorkTypeMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check(); // Доступ к админке обычно проверяется middleware группы
    }

    public function rules(): array
    {
        return [
            'materials' => 'required|array|min:1',
            'materials.*.material_id' => 'required|integer|exists:materials,id', // TODO: Добавить проверку на принадлежность материала организации
            'materials.*.default_quantity' => 'required|numeric|min:0',
            'materials.*.notes' => 'nullable|string|max:1000',
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
                // organization_id и work_type_id будут установлены в сервисе
            );
        }
        return $dtos;
    }
} 