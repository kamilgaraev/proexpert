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
        /** @var WorkType|null $workType */
        $workType = $this->route('work_type'); // Laravel автоматически инжектирует модель
        
        if (!$workType || !($workType instanceof WorkType)) {
            return false;
        }
        
        $organizationId = $this->get('current_organization_id');
        if (!$organizationId) {
            return false;
        }
        
        // Проверяем права и принадлежность объекта к организации текущего пользователя
        return Gate::allows('manage-catalogs') && $workType->organization_id === (int)$organizationId;
    }

    public function rules(): array
    {
        /** @var WorkType|null $workType */
        $workType = $this->route('work_type'); 
        $workTypeId = $workType?->id;
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
            'category' => 'nullable|string|max:100',
            'is_active' => 'sometimes|boolean',
        ];
    }
}