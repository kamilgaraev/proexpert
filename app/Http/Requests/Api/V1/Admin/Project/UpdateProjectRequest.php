<?php

namespace App\Http\Requests\Api\V1\Admin\Project;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use App\Models\Role;
use App\Models\Project; // Импортируем модель Project

class UpdateProjectRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = Auth::user();
        $organizationId = $this->attributes->get('organization_id');
        /** @var Project|null $project */
        $project = $this->route('project');

        // 1. Пользователь аутентифицирован?
        // 2. Контекст организации установлен?
        // 3. Пользователь - админ этой организации?
        // 4. Проект существует и принадлежит этой организации?
        return $user && 
               $organizationId && 
               $user->isOrganizationAdmin($organizationId) &&
               $project && 
               $project->organization_id === $organizationId;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:255',
            'address' => 'nullable|string|max:1000',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'status' => 'sometimes|required|string|in:active,completed,planned,on_hold',
            'is_archived' => 'sometimes|boolean',
            // Добавить другие поля
        ];
    }
} 