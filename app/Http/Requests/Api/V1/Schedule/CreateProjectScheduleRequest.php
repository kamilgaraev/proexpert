<?php

namespace App\Http\Requests\Api\V1\Schedule;

use Illuminate\Foundation\Http\FormRequest;
use App\Enums\Schedule\ScheduleStatusEnum;
use App\Domain\Authorization\Services\AuthorizationService;

class CreateProjectScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        
        if (!$user) {
            return false;
        }
        
        $organizationId = $this->getOrganizationId();
        
        if (!$organizationId) {
            return false;
        }
        
        $authorizationService = app(AuthorizationService::class);
        
        return $authorizationService->can($user, 'schedule.create', [
            'organization_id' => $organizationId,
            'context_type' => 'organization'
        ]);
    }
    
    protected function getOrganizationId(): ?int
    {
        $user = $this->user();
        $organizationId = $user->current_organization_id ?? $user->organization_id;
        
        return $organizationId ? (int) $organizationId : null;
    }

    public function rules(): array
    {
        return [
            'project_id' => 'sometimes|integer|exists:projects,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'planned_start_date' => 'required|date|after_or_equal:today',
            'planned_end_date' => 'required|date|after:planned_start_date',
            'status' => 'nullable|string|in:draft,active',
            'is_template' => 'nullable|boolean',
            'template_name' => 'nullable|string|max:255|required_if:is_template,true',
            'template_description' => 'nullable|string|max:1000',
            'calculation_settings' => 'nullable|array',
            'calculation_settings.auto_schedule' => 'nullable|boolean',
            'calculation_settings.level_resources' => 'nullable|boolean',
            'calculation_settings.working_days_per_week' => 'nullable|integer|min:1|max:7',
            'calculation_settings.working_hours_per_day' => 'nullable|numeric|min:1|max:24',
            'display_settings' => 'nullable|array',
            'display_settings.show_critical_path' => 'nullable|boolean',
            'display_settings.show_float' => 'nullable|boolean',
            'display_settings.show_baseline' => 'nullable|boolean',
            'total_estimated_cost' => 'nullable|numeric|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'project_id.required' => 'Необходимо указать проект',
            'project_id.exists' => 'Указанный проект не существует',
            'name.required' => 'Название графика обязательно',
            'name.max' => 'Название графика не должно превышать 255 символов',
            'planned_start_date.required' => 'Дата начала обязательна',
            'planned_start_date.after_or_equal' => 'Дата начала не может быть в прошлом',
            'planned_end_date.required' => 'Дата окончания обязательна',
            'planned_end_date.after' => 'Дата окончания должна быть позже даты начала',
            'template_name.required_if' => 'Для шаблона необходимо указать название',
            'total_estimated_cost.min' => 'Стоимость не может быть отрицательной',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Устанавливаем значения по умолчанию
        $this->merge([
            'is_template' => $this->boolean('is_template'),
            'calculation_settings' => $this->input('calculation_settings', [
                'auto_schedule' => true,
                'level_resources' => false,
                'working_days_per_week' => 5,
                'working_hours_per_day' => 8,
            ]),
            'display_settings' => $this->input('display_settings', [
                'show_critical_path' => true,
                'show_float' => false,
                'show_baseline' => false,
            ]),
        ]);
    }
} 