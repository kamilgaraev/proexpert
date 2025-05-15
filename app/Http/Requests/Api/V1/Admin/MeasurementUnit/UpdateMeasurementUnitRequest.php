<?php

namespace App\Http\Requests\Api\V1\Admin\MeasurementUnit;

use Illuminate\Foundation\Http\FormRequest;
use App\DTOs\MeasurementUnit\MeasurementUnitDTO;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;
// Если MeasurementUnit модель будет использоваться для can(), ее нужно импортировать
// use App\Models\MeasurementUnit;

class UpdateMeasurementUnitRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Предполагаем, что авторизация проверяется через middleware или политики.
        // $measurementUnit = $this->route('measurement_unit'); // Получение модели из маршрута
        // return Auth::user()->can('update', $measurementUnit);
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $organizationId = Auth::user()->organization_id;
        $measurementUnitId = $this->route('measurement_unit'); // Получаем ID из маршрута

        return [
            'name' => [
                'sometimes', // 'sometimes' означает, что поле будет проверяться, только если оно присутствует в запросе
                'required',
                'string',
                'max:255',
                Rule::unique('measurement_units')->where(function ($query) use ($organizationId) {
                    return $query->where('organization_id', $organizationId);
                })->ignore($measurementUnitId),
            ],
            'short_name' => 'sometimes|required|string|max:50',
            'type' => 'sometimes|nullable|string|in:material,work,other',
            'description' => 'sometimes|nullable|string',
            'is_default' => 'sometimes|nullable|boolean',
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
        
        // Создаем DTO только с теми полями, которые были переданы и валидированы.
        // Однако, для простоты и учитывая, что DTO ожидает все поля в конструкторе,
        // мы можем передать null или значения по умолчанию для отсутствующих полей.
        // Если модель загружается в сервисе, то DTO может быть использован для частичного обновления.
        // Здесь мы создаем DTO с потенциально всеми полями, ожидая, что сервис обработает это корректно
        // (например, получит существующую модель и обновит только переданные поля).

        return new MeasurementUnitDTO(
            name: $validatedData['name'] ?? null, // Если поле не передано, оно не будет в $validatedData
            short_name: $validatedData['short_name'] ?? null,
            type: $validatedData['type'] ?? null,
            description: $validatedData['description'] ?? null,
            is_default: $validatedData['is_default'] ?? null,
            organization_id: null // Будет установлено в сервисе
        );
    }
} 