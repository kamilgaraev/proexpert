<?php

namespace App\Http\Requests\Api\V1\Admin\WorkType;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use App\Models\WorkType; // Импортируем модель

class UpdateWorkTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Получаем ID из маршрута и находим модель вручную
        $workTypeId = $this->route('work_type');
        $workType = WorkType::find($workTypeId);
        
        // Проверяем права и принадлежность объекта к организации текущего пользователя
        return $workType && Gate::allows('admin.catalogs.manage') && $workType->organization_id === (int)$this->get('current_organization_id');
    }

    public function rules(): array
    {
        // Получаем ID из маршрута
        $workTypeId = $this->route('work_type');
        $organizationId = $this->get('current_organization_id');
        
        if (!$organizationId || !$workTypeId) {
             return []; // Не должно произойти, если authorize прошел
        }

        return [
             'name' => [
                 'sometimes',
                 'required',
                 'string',
                 'max:255',
                 Rule::unique('work_types', 'name')
                    ->where(function ($query) use ($organizationId) {
                        return $query->where('organization_id', $organizationId)
                                    ->whereNull('deleted_at');
                    })
                    ->ignore($workTypeId), // Игнорируем текущий ID
            ],
            'measurement_unit_id' => 'sometimes|required|integer|exists:measurement_units,id',
            'code' => 'nullable|string|max:100',
            'category' => 'nullable|string|max:100',
            'description' => 'nullable|string',
            'default_price' => 'nullable|numeric|min:0',
            'additional_properties' => 'nullable|array',
            'is_active' => 'sometimes|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Название вида работ обязательно для заполнения.',
            'name.unique' => 'Вид работ с таким названием уже существует в вашей организации.',
            'measurement_unit_id.required' => 'Необходимо указать единицу измерения.',
            'measurement_unit_id.exists' => 'Выбранная единица измерения не существует.',
            'default_price.numeric' => 'Цена по умолчанию должна быть числом.',
            'default_price.min' => 'Цена по умолчанию не может быть отрицательной.',
        ];
    }
}