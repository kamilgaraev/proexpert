<?php

namespace App\Http\Requests\Api\V1\Admin\SiteRequest;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use App\DTOs\SiteRequest\SiteRequestDTO;
use App\Enums\SiteRequest\SiteRequestTypeEnum;
use App\Enums\SiteRequest\SiteRequestStatusEnum;
use App\Enums\SiteRequest\SiteRequestPriorityEnum;
use App\Enums\SiteRequest\PersonnelTypeEnum;

class StoreSiteRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check() && Auth::user()->can('access-admin-panel');
    }

    public function rules(): array
    {
        return [
            'organization_id' => ['required', 'integer', 'exists:organizations,id'],
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'user_id' => ['sometimes', 'integer', 'exists:users,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'request_type' => ['required', 'string', 'in:' . implode(',', array_column(SiteRequestTypeEnum::cases(), 'value'))],
            'status' => ['sometimes', 'string', 'in:' . implode(',', array_column(SiteRequestStatusEnum::cases(), 'value'))],
            'priority' => ['required', 'string', 'in:' . implode(',', array_column(SiteRequestPriorityEnum::cases(), 'value'))],
            'required_date' => ['required', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string'],
            'files' => ['sometimes', 'array'],
            'files.*' => ['file', 'max:10240'],
            'personnel_type' => ['required_if:request_type,personnel_request', 'nullable', 'string', 'in:' . implode(',', array_column(PersonnelTypeEnum::cases(), 'value'))],
            'personnel_count' => ['required_if:request_type,personnel_request', 'nullable', 'integer', 'min:1', 'max:100'],
            'personnel_requirements' => ['required_if:request_type,personnel_request', 'nullable', 'string'],
            'hourly_rate' => ['required_if:request_type,personnel_request', 'nullable', 'numeric', 'min:0', 'max:10000'],
            'work_hours_per_day' => ['required_if:request_type,personnel_request', 'nullable', 'integer', 'min:1', 'max:24'],
            'work_start_date' => ['required_if:request_type,personnel_request', 'nullable', 'date', 'after_or_equal:today'],
            'work_end_date' => ['required_if:request_type,personnel_request', 'nullable', 'date', 'after:work_start_date'],
            'work_location' => ['required_if:request_type,personnel_request', 'nullable', 'string', 'max:500'],
            'additional_conditions' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'organization_id.required' => 'Организация обязательна для заполнения',
            'organization_id.exists' => 'Выбранная организация не существует',
            'project_id.required' => 'Проект обязателен для заполнения',
            'project_id.exists' => 'Выбранный проект не существует',
            'user_id.exists' => 'Выбранный пользователь не существует',
            'title.required' => 'Заголовок обязателен для заполнения',
            'title.max' => 'Заголовок не должен превышать 255 символов',
            'description.required' => 'Описание обязательно для заполнения',
            'request_type.required' => 'Тип заявки обязателен для заполнения',
            'request_type.in' => 'Недопустимый тип заявки',
            'status.in' => 'Недопустимый статус заявки',
            'priority.required' => 'Приоритет обязателен для заполнения',
            'priority.in' => 'Недопустимый приоритет',
            'required_date.required' => 'Требуемая дата обязательна для заполнения',
            'required_date.date' => 'Требуемая дата должна быть корректной датой',
            'required_date.after_or_equal' => 'Требуемая дата не может быть в прошлом',
            'files.*.file' => 'Каждый элемент должен быть файлом',
            'files.*.max' => 'Размер файла не должен превышать 10MB',
            'personnel_type.required_if' => 'Тип персонала обязателен для заявок на персонал',
            'personnel_type.in' => 'Недопустимый тип персонала',
            'personnel_count.required_if' => 'Количество персонала обязательно для заявок на персонал',
            'personnel_count.integer' => 'Количество персонала должно быть числом',
            'personnel_count.min' => 'Количество персонала должно быть не менее 1',
            'personnel_count.max' => 'Количество персонала не должно превышать 100',
            'personnel_requirements.required_if' => 'Требования к персоналу обязательны для заявок на персонал',
            'hourly_rate.required_if' => 'Почасовая ставка обязательна для заявок на персонал',
            'hourly_rate.numeric' => 'Почасовая ставка должна быть числом',
            'hourly_rate.min' => 'Почасовая ставка не может быть отрицательной',
            'hourly_rate.max' => 'Почасовая ставка не должна превышать 10000',
            'work_hours_per_day.required_if' => 'Количество рабочих часов в день обязательно для заявок на персонал',
            'work_hours_per_day.integer' => 'Количество рабочих часов должно быть числом',
            'work_hours_per_day.min' => 'Количество рабочих часов должно быть не менее 1',
            'work_hours_per_day.max' => 'Количество рабочих часов не должно превышать 24',
            'work_start_date.required_if' => 'Дата начала работы обязательна для заявок на персонал',
            'work_start_date.date' => 'Дата начала работы должна быть корректной датой',
            'work_start_date.after_or_equal' => 'Дата начала работы не может быть в прошлом',
            'work_end_date.required_if' => 'Дата окончания работы обязательна для заявок на персонал',
            'work_end_date.date' => 'Дата окончания работы должна быть корректной датой',
            'work_end_date.after' => 'Дата окончания работы должна быть после даты начала',
            'work_location.required_if' => 'Место работы обязательно для заявок на персонал',
            'work_location.max' => 'Место работы не должно превышать 500 символов',
        ];
    }

    public function toDTO(): SiteRequestDTO
    {
        $validated = $this->validated();
        
        return new SiteRequestDTO(
            organizationId: $validated['organization_id'],
            projectId: $validated['project_id'],
            userId: $validated['user_id'] ?? Auth::id(),
            title: $validated['title'],
            description: $validated['description'],
            requestType: $validated['request_type'],
            status: $validated['status'] ?? SiteRequestStatusEnum::PENDING->value,
            priority: $validated['priority'],
            requiredDate: $validated['required_date'],
            notes: $validated['notes'] ?? null,
            files: $validated['files'] ?? [],
            personnelType: $validated['personnel_type'] ?? null,
            personnelCount: $validated['personnel_count'] ?? null,
            personnelRequirements: $validated['personnel_requirements'] ?? null,
            hourlyRate: $validated['hourly_rate'] ?? null,
            workHoursPerDay: $validated['work_hours_per_day'] ?? null,
            workStartDate: $validated['work_start_date'] ?? null,
            workEndDate: $validated['work_end_date'] ?? null,
            workLocation: $validated['work_location'] ?? null,
            additionalConditions: $validated['additional_conditions'] ?? null
        );
    }
}