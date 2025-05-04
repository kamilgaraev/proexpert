<?php

namespace App\Http\Requests\Api\V1\Mobile\Log;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use App\Models\Project;

class StoreWorkCompletionRequest extends FormRequest
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
        $project = Project::find($this->input('project_id'));
        $organizationId = $project?->organization_id;

        return [
            'project_id' => 'required|integer|exists:projects,id',
            'work_type_id' => [
                'required',
                'integer',
                // Проверяем, что вид работы существует и принадлежит организации проекта
                function ($attribute, $value, $fail) use ($organizationId) {
                    if (!$organizationId) return;
                    $workTypeExists = \App\Models\WorkType::where('id', $value)
                                                      ->where('organization_id', $organizationId)
                                                      ->exists();
                    if (!$workTypeExists) {
                        $fail('Выбранный вид работы не найден в организации проекта.');
                    }
                },
            ],
            'quantity' => 'nullable|numeric|min:0', // Количество может быть 0 или больше, необязательно
            'completion_date' => 'required|date_format:Y-m-d',
            'notes' => 'nullable|string|max:1000',
        ];
    }
} 