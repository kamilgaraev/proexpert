<?php

namespace App\Http\Requests\Api\V1\Mobile\Log;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use App\Models\Project;
use App\Models\Material;
use App\Models\WorkType;
use Illuminate\Validation\Rule;

class StoreMaterialWriteOffRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        $projectId = $this->input('project_id');
        if (!$user || !$projectId) {
            return false;
        }
        return Project::where('id', $projectId)
                    ->whereHas('users', function ($query) use ($user) {
                        $query->where('user_id', $user->id);
                    })
                    ->exists();
    }

    public function rules(): array
    {
        $project = Project::find($this->input('project_id'));
        $organizationId = $project?->organization_id;

        return [
            'project_id' => 'required|integer|exists:projects,id',
            'material_id' => [
                'required',
                'integer',
                Rule::exists(Material::class, 'id')->where(function ($query) use ($organizationId) {
                    if ($organizationId) {
                        $query->where('organization_id', $organizationId);
                    }
                    // TODO: Добавить проверку, что материал есть на остатках проекта, если это требуется на уровне валидации
                    // Это может быть сложно сделать здесь эффективно. Лучше в сервисе.
                }),
            ],
            'quantity' => 'required|numeric|min:0.001',
            'usage_date' => 'required|date_format:Y-m-d', // Дата списания
            'work_type_id' => [
                'nullable', // Может быть не указан, если просто списание без привязки к работе
                'integer',
                Rule::exists(WorkType::class, 'id')->where(function ($query) use ($organizationId) {
                    if ($organizationId) {
                        $query->where('organization_id', $organizationId);
                    }
                }),
            ],
            // 'cost_category_id' => 'nullable|integer|exists:cost_categories,id', // Если используется
            'notes' => 'nullable|string|max:1000',
        ];
    }

    protected function prepareForValidation()
    {
        if ($this->has('usage_date') && !is_null($this->usage_date)) {
            try {
                $this->merge([
                    'usage_date' => \Carbon\Carbon::parse($this->usage_date)->format('Y-m-d'),
                ]);
            } catch (\Exception $e) {
                // Оставить как есть
            }
        }
    }
} 