<?php

namespace App\Http\Requests\Api\V1\Admin\SiteRequest;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use App\DTOs\SiteRequest\SiteRequestDTO;
use App\Enums\SiteRequest\SiteRequestTypeEnum;
use App\Enums\SiteRequest\SiteRequestStatusEnum;
use App\Enums\SiteRequest\SiteRequestPriorityEnum;
use App\Enums\SiteRequest\PersonnelTypeEnum;
use App\Models\SiteRequest;

class UpdateSiteRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var SiteRequest $siteRequest */
        $siteRequest = $this->route('site_request');
        
        // Админ может обновлять любые заявки в своей организации
        return Auth::check() && 
               Auth::user()->can('access-admin-panel') &&
               $siteRequest->organization_id === Auth::user()->current_organization_id;
    }

    public function rules(): array
    {
        return [
            // Основные поля (все опциональные при обновлении)
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'string'],
            'request_type' => ['sometimes', 'string', 'in:' . implode(',', array_column(SiteRequestTypeEnum::cases(), 'value'))],
            'status' => ['sometimes', 'string', 'in:' . implode(',', array_column(SiteRequestStatusEnum::cases(), 'value'))],
            'priority' => ['sometimes', 'string', 'in:' . implode(',', array_column(SiteRequestPriorityEnum::cases(), 'value'))],
            'required_date' => ['sometimes', 'date', 'after_or_equal:today'],
            'notes' => ['sometimes', 'nullable', 'string'],
            
            // Поля для заявок на персонал (опциональные)
            'personnel_type' => ['sometimes', 'nullable', 'string', 'in:' . implode(',', array_column(PersonnelTypeEnum::cases(), 'value'))],
            'personnel_count' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
            'personnel_requirements' => ['sometimes', 'nullable', 'string'],
            'hourly_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:10000'],
            'work_hours_per_day' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:24'],
            'work_start_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:today'],
            'work_end_date' => ['sometimes', 'nullable', 'date', 'after:work_start_date'],
            'work_location' => ['sometimes', 'nullable', 'string', 'max:500'],
            'additional_conditions' => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.max' => 'Заголовок не должен превышать 255 символов',
            'request_type.in' => 'Недопустимый тип заявки',
            'status.in' => 'Недопустимый статус заявки',
            'priority.in' => 'Недопустимый приоритет',
            'required_date.date' => 'Требуемая дата должна быть корректной датой',
            'required_date.after_or_equal' => 'Требуемая дата не может быть в прошлом',
            
            // Сообщения для полей персонала
            'personnel_type.in' => 'Недопустимый тип персонала',
            'personnel_count.integer' => 'Количество персонала должно быть числом',
            'personnel_count.min' => 'Количество персонала должно быть не менее 1',
            'personnel_count.max' => 'Количество персонала не должно превышать 100',
            'hourly_rate.numeric' => 'Почасовая ставка должна быть числом',
            'hourly_rate.min' => 'Почасовая ставка не может быть отрицательной',
            'hourly_rate.max' => 'Почасовая ставка не должна превышать 10000',
            'work_hours_per_day.integer' => 'Количество рабочих часов должно быть числом',
            'work_hours_per_day.min' => 'Количество рабочих часов должно быть не менее 1',
            'work_hours_per_day.max' => 'Количество рабочих часов не должно превышать 24',
            'work_start_date.date' => 'Дата начала работы должна быть корректной датой',
            'work_start_date.after_or_equal' => 'Дата начала работы не может быть в прошлом',
            'work_end_date.date' => 'Дата окончания работы должна быть корректной датой',
            'work_end_date.after' => 'Дата окончания работы должна быть после даты начала',
            'work_location.max' => 'Место работы не должно превышать 500 символов',
        ];
    }

    public function toDTO(): SiteRequestDTO
    {
        $validated = $this->validated();
        /** @var SiteRequest $siteRequest */
        $siteRequest = $this->route('site_request');
        
        return new SiteRequestDTO(
            organizationId: $siteRequest->organization_id,
            projectId: $siteRequest->project_id,
            userId: $siteRequest->user_id,
            title: $validated['title'] ?? $siteRequest->title,
            description: $validated['description'] ?? $siteRequest->description,
            requestType: $validated['request_type'] ?? $siteRequest->request_type,
            status: $validated['status'] ?? $siteRequest->status,
            priority: $validated['priority'] ?? $siteRequest->priority,
            requiredDate: $validated['required_date'] ?? $siteRequest->required_date,
            notes: $validated['notes'] ?? $siteRequest->notes,
            files: [], // Файлы обновляются отдельно
            personnelType: $validated['personnel_type'] ?? $siteRequest->personnel_type,
            personnelCount: $validated['personnel_count'] ?? $siteRequest->personnel_count,
            personnelRequirements: $validated['personnel_requirements'] ?? $siteRequest->personnel_requirements,
            hourlyRate: $validated['hourly_rate'] ?? $siteRequest->hourly_rate,
            workHoursPerDay: $validated['work_hours_per_day'] ?? $siteRequest->work_hours_per_day,
            workStartDate: $validated['work_start_date'] ?? $siteRequest->work_start_date,
            workEndDate: $validated['work_end_date'] ?? $siteRequest->work_end_date,
            workLocation: $validated['work_location'] ?? $siteRequest->work_location,
            additionalConditions: $validated['additional_conditions'] ?? $siteRequest->additional_conditions
        );
    }
}