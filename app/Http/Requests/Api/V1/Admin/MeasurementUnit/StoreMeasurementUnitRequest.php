<?php

namespace App\Http\Requests\Api\V1\Admin\MeasurementUnit;

use Illuminate\Foundation\Http\FormRequest;
use App\DTOs\MeasurementUnit\MeasurementUnitDTO;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;

class StoreMeasurementUnitRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Предполагаем, что авторизация проверяется через middleware или политики.
        // Для простоты пока возвращаем true.
        // return Auth::user()->can('create', MeasurementUnit::class);
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $organizationId = Auth::user()->organization_id; // Или другой способ получения ID организации

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('measurement_units')->where(function ($query) use ($organizationId) {
                    return $query->where('organization_id', $organizationId);
                }),
            ],
            'short_name' => 'required|string|max:50',
            'type' => 'nullable|string|in:material,work,other', // Допустимые типы, если есть
            'description' => 'nullable|string',
            'is_default' => 'nullable|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Название единицы измерения обязательно для заполнения.',
            'name.unique' => 'Единица измерения с таким названием уже существует в вашей организации.',
            'short_name.required' => 'Краткое обозначение обязательно для заполнения.',
        ];
    }

    public function toDto(): MeasurementUnitDTO
    {
        $validatedData = $this->validated();
        return new MeasurementUnitDTO(
            name: $validatedData['name'],
            short_name: $validatedData['short_name'],
            type: $validatedData['type'] ?? 'material',
            description: $validatedData['description'] ?? null,
            is_default: $validatedData['is_default'] ?? false,
            organization_id: null // Будет установлено в сервисе
        );
    }
} 