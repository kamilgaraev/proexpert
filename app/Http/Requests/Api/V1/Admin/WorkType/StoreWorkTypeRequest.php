<?php

namespace App\Http\Requests\Api\V1\Admin\WorkType;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreWorkTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Проверяем общие права на управление справочниками
        return Gate::allows('manage-catalogs');
    }

    public function rules(): array
    {
        // Получаем ID организации из атрибутов запроса (установленных middleware)
        $organizationId = $this->attributes->get('organization_id');
        if (!$organizationId) {
             // Если ID организации не определен, валидация не должна пройти
             // Можно добавить правило, которое всегда провалится, или вернуть пустой массив,
             // но лучше выбрасывать исключение или проверять в authorize 
             // (хотя authorize уже должен отсечь по Gate)
             return []; 
        }

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('work_types', 'name')
                    ->where(function ($query) use ($organizationId) {
                        return $query->where('organization_id', $organizationId)
                                    ->whereNull('deleted_at'); // Учитываем SoftDeletes
                    }),
            ],
            'measurement_unit_id' => 'required|integer|exists:measurement_units,id',
            'category' => 'nullable|string|max:100',
            'is_active' => 'sometimes|boolean',
        ];
    }
} 