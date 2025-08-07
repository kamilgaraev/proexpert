<?php

namespace App\Http\Requests\Api\V1\Mobile\SiteRequest;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use App\DTOs\SiteRequest\SiteRequestDTO;
use App\Enums\SiteRequest\SiteRequestStatusEnum;
use App\Enums\SiteRequest\SiteRequestPriorityEnum;
use App\Enums\SiteRequest\SiteRequestTypeEnum;
use App\Enums\SiteRequest\PersonnelTypeEnum;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreSiteRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check();
    }

    public function rules(): array
    {
        $organizationId = Auth::user()->current_organization_id;

        return [
            'project_id' => ['required', 'integer', Rule::exists('projects', 'id')->where('organization_id', $organizationId)],
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:65535',
            'status' => ['required', new Enum(SiteRequestStatusEnum::class)],
            'priority' => ['required', new Enum(SiteRequestPriorityEnum::class)],
            'request_type' => ['required', new Enum(SiteRequestTypeEnum::class)],
            'required_date' => 'nullable|date_format:Y-m-d|after_or_equal:today',
            'notes' => 'nullable|string|max:65535',
            'files' => 'nullable|array|max:10',
            'files.*' => 'required|file|image|mimes:jpeg,png,jpg,gif|max:5120',
            
            'personnel_type' => ['nullable', new Enum(PersonnelTypeEnum::class), 'required_if:request_type,personnel_request'],
            'personnel_count' => 'nullable|integer|min:1|max:100|required_if:request_type,personnel_request',
            'personnel_requirements' => 'nullable|string|max:2000',
            'hourly_rate' => 'nullable|numeric|min:0|max:10000',
            'work_hours_per_day' => 'nullable|integer|min:1|max:24',
            'work_start_date' => 'nullable|date_format:Y-m-d|after_or_equal:today',
            'work_end_date' => 'nullable|date_format:Y-m-d|after_or_equal:work_start_date',
            'work_location' => 'nullable|string|max:500',
            'additional_conditions' => 'nullable|string|max:2000',
        ];
    }

    public function messages(): array
    {
        return [
            'files.*.image' => 'Каждый файл должен быть изображением.',
            'files.*.mimes' => 'Разрешены только файлы jpeg, png, jpg, gif.',
            'files.*.max' => 'Максимальный размер каждого файла 5MB.',
            'files.max' => 'Можно загрузить не более 10 файлов.',
            
            // Сообщения для полей персонала
            'personnel_type.required_if' => 'Тип персонала обязателен для заявок на людей.',
            'personnel_count.required_if' => 'Количество персонала обязательно для заявок на людей.',
            'personnel_count.min' => 'Количество персонала должно быть не менее 1.',
            'personnel_count.max' => 'Количество персонала не может превышать 100.',
            'work_end_date.after_or_equal' => 'Дата окончания работ должна быть не раньше даты начала.',
        ];
    }

    public function toDto(): SiteRequestDTO
    {
        $validatedData = $this->validated();
        
        return new SiteRequestDTO(
            organization_id: Auth::user()->current_organization_id,
            project_id: $validatedData['project_id'],
            user_id: Auth::id(), // ID текущего аутентифицированного пользователя (прораба)
            title: $validatedData['title'],
            description: $validatedData['description'] ?? null,
            status: SiteRequestStatusEnum::from($validatedData['status']),
            priority: SiteRequestPriorityEnum::from($validatedData['priority']),
            request_type: SiteRequestTypeEnum::from($validatedData['request_type']),
            required_date: $validatedData['required_date'] ?? null,
            notes: $validatedData['notes'] ?? null,
            files: $this->file('files') ?? [], // Получаем загруженные файлы
            
            // Поля для заявок на персонал
            personnel_type: isset($validatedData['personnel_type']) ? PersonnelTypeEnum::from($validatedData['personnel_type']) : null,
            personnel_count: $validatedData['personnel_count'] ?? null,
            personnel_requirements: $validatedData['personnel_requirements'] ?? null,
            hourly_rate: $validatedData['hourly_rate'] ?? null,
            work_hours_per_day: $validatedData['work_hours_per_day'] ?? null,
            work_start_date: $validatedData['work_start_date'] ?? null,
            work_end_date: $validatedData['work_end_date'] ?? null,
            work_location: $validatedData['work_location'] ?? null,
            additional_conditions: $validatedData['additional_conditions'] ?? null,
        );
    }
}