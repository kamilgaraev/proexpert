<?php

namespace App\Http\Requests\Api\V1\Mobile\Log;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use App\Models\Project;

class StoreMaterialUsageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = Auth::user();
        $projectId = $this->input('project_id');

        if (!$user || !$projectId) {
            return false;
        }

        // Проверяем, назначен ли пользователь (прораб) на данный проект
        // Используем метод exists для эффективности
        return Project::where('id', $projectId)
                    ->whereHas('users', function ($query) use ($user) {
                        $query->where('user_id', $user->id);
                    })
                    ->exists();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        // Получаем ID организации из проекта (уже проверили доступ в authorize)
        $project = Project::find($this->input('project_id'));
        $organizationId = $project?->organization_id;

        return [
            'project_id' => 'required|integer|exists:projects,id', // Проверка существования проекта
            'material_id' => [
                'required',
                'integer',
                // Проверяем, что материал существует и принадлежит организации проекта
                function ($attribute, $value, $fail) use ($organizationId) {
                    if (!$organizationId) return; // Если организация не найдена, другая валидация сработает
                    $materialExists = \App\Models\Material::where('id', $value)
                                                      ->where('organization_id', $organizationId)
                                                      ->exists();
                    if (!$materialExists) {
                        $fail('Выбранный материал не найден в организации проекта.');
                    }
                },
            ],
            'quantity' => 'required|numeric|min:0.001', // Минимальное значение > 0
            'usage_date' => 'required|date_format:Y-m-d', // Ожидаем дату в формате ГГГГ-ММ-ДД
            'notes' => 'nullable|string|max:1000', // Заметки необязательны
        ];
    }
} 