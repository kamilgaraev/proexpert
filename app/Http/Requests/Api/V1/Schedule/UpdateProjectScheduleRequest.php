<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Schedule;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\ProjectSchedule;
use Illuminate\Foundation\Http\FormRequest;

use function trans_message;

class UpdateProjectScheduleRequest extends FormRequest
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

        return $authorizationService->can($user, 'schedule.edit', [
            'organization_id' => $organizationId,
            'context_type' => 'organization',
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
            'name' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|nullable|string|max:5000',
            'planned_start_date' => 'sometimes|required|date',
            'planned_end_date' => 'sometimes|required|date|after:planned_start_date',
            'status' => 'sometimes|required|string|in:draft,active,paused,completed,cancelled',
            'is_template' => 'sometimes|boolean',
            'template_name' => 'sometimes|nullable|string|max:255|required_if:is_template,true',
            'template_description' => 'sometimes|nullable|string|max:1000',
            'calculation_settings' => 'sometimes|nullable|array',
            'calculation_settings.auto_schedule' => 'sometimes|boolean',
            'calculation_settings.level_resources' => 'sometimes|boolean',
            'calculation_settings.working_days_per_week' => 'sometimes|integer|min:1|max:7',
            'calculation_settings.working_hours_per_day' => 'sometimes|numeric|min:1|max:24',
            'display_settings' => 'sometimes|nullable|array',
            'display_settings.show_critical_path' => 'sometimes|boolean',
            'display_settings.show_float' => 'sometimes|boolean',
            'display_settings.show_baseline' => 'sometimes|boolean',
            'total_estimated_cost' => 'sometimes|nullable|numeric|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Название графика обязательно',
            'name.max' => 'Название графика не должно превышать 255 символов',
            'planned_start_date.required' => 'Дата начала обязательна',
            'planned_end_date.required' => 'Дата окончания обязательна',
            'planned_end_date.after' => 'Дата окончания должна быть позже даты начала',
            'status.in' => 'Недопустимый статус графика',
            'template_name.required_if' => 'Для шаблона необходимо указать название',
            'total_estimated_cost.min' => 'Стоимость не может быть отрицательной',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if (!$this->has('status') || $this->status !== 'completed') {
                return;
            }

            $schedule = $this->resolveRouteSchedule();

            if (!$schedule instanceof ProjectSchedule) {
                return;
            }

            if ($schedule->calculateOverallProgressPercent() < 100.0) {
                $validator->errors()->add(
                    'status',
                    trans_message('schedule_management.schedule_completion_requires_full_progress')
                );
            }
        });
    }

    private function resolveRouteSchedule(): ?ProjectSchedule
    {
        $schedule = $this->route('schedule');

        if ($schedule instanceof ProjectSchedule) {
            return $schedule;
        }

        if (is_numeric($schedule)) {
            return ProjectSchedule::query()->find((int) $schedule);
        }

        return null;
    }
}
