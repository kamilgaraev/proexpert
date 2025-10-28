<?php

namespace App\Http\Requests\Api\V1\Admin\WorkType;

use Illuminate\Foundation\Http\FormRequest;
// use Illuminate\Support\Facades\Gate; // Gate больше не используется здесь
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class StoreWorkTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        Log::info('[StoreWorkTypeRequest] authorize() CALLED.');
        // return Gate::allows('manage-catalogs'); // Убрано, доступ проверяется на уровне роутера/контроллера
        return true; 
    }

    public function rules(): array
    {
        Log::info('[StoreWorkTypeRequest] rules() CALLED.');
        $organizationId = Auth::user() ? Auth::user()->current_organization_id : null;
        Log::info('[StoreWorkTypeRequest] organization_id from Auth::user()', ['org_id' => $organizationId]);

        if (!$organizationId) {
            Log::error('[StoreWorkTypeRequest] Organization ID is NULL in rules()!');
            return [
                // Возвращаем специфичное правило, чтобы увидеть его в ошибке валидации если дойдет
                'critical_organization_id_missing' => 'required' 
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
                                    ->whereNull('deleted_at');
                    }),
            ],
            'measurement_unit_id' => 'required|integer|exists:measurement_units,id',
            'category' => 'nullable|string|max:100',
            'is_active' => 'sometimes|boolean',
            'code' => 'nullable|string|max:100',
            'description' => 'nullable|string',
            'default_price' => 'nullable|numeric|min:0',
            'additional_properties' => 'nullable|array',
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
            'default_price.numeric' => 'Цена по умолчанию должна быть числом.',
            'default_price.min' => 'Цена по умолчанию не может быть отрицательной.',
        ];
    }
} 