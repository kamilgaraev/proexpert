<?php

namespace App\Http\Requests\Api\V1\Admin\WorkType;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;

class StoreWorkTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Проверяем общие права на управление справочниками
        return Gate::allows('manage-catalogs');
    }

    public function rules(): array
    {
        // Получаем ID организации из аутентифицированного пользователя
        $organizationId = Auth::user()->current_organization_id;

        if (!$organizationId) {
            // Эта ситуация не должна происходить, если пользователь аутентифицирован
            // и middleware OrganizationContext отработал правильно.
            // Возвращаем правило, которое всегда провалит валидацию, если ID организации не определен.
            return [
                'organization_id' => 'required' // Это вызовет ошибку валидации, если organization_id не будет найден
            ];
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
            // Добавляем неявные поля, которые могут приходить от фронтенда, но не должны сохраняться напрямую
            // или должны быть обработаны отдельно в контроллере/сервисе, если они нужны.
            // 'description' => 'nullable|string', 
            // 'external_code' => 'nullable|string|max:255',
        ];
    }

    /**
     * Сообщения для правил валидации.
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Название вида работ обязательно для заполнения.',
            'name.unique' => 'Вид работ с таким названием уже существует в вашей организации.',
            'measurement_unit_id.required' => 'Необходимо указать единицу измерения.',
            'measurement_unit_id.exists' => 'Выбранная единица измерения не существует.',
            'organization_id.required' => 'Не удалось определить организацию. Обратитесь к администратору.' // Сообщение для случая, если $organizationId не определен
        ];
    }
} 