<?php

namespace App\Http\Requests\Api\V1\Admin\Material;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;
use App\Models\Role;
use App\Models\Material; // Импортируем модель Material

class UpdateMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        $organizationId = $this->attributes->get('organization_id');
        /** @var Material|null $material */
        $material = $this->route('material');

        // Аналогично UpdateProjectRequest
        return $user && 
               $organizationId && 
               $user->isOrganizationAdmin($organizationId) &&
               $material && 
               $material->organization_id === $organizationId;
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
} 