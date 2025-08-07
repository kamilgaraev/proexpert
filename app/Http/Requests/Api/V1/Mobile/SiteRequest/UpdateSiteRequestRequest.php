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
use App\Models\SiteRequest;

class UpdateSiteRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var SiteRequest $siteRequest */
        $siteRequest = $this->route('site_request');
        return Auth::check() && ($siteRequest->user_id === Auth::id() || Auth::user()->can('manage_site_requests'));
    }

    public function rules(): array
    {
        /** @var SiteRequest $siteRequest */
        $siteRequest = $this->route('site_request');
        $organizationId = $siteRequest->organization_id;

        return [
            'project_id' => ['sometimes', 'required', 'integer', Rule::exists('projects', 'id')->where('organization_id', $organizationId)],
            'title' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|nullable|string|max:65535',
            'status' => ['sometimes', 'required', new Enum(SiteRequestStatusEnum::class)],
            'priority' => ['sometimes', 'required', new Enum(SiteRequestPriorityEnum::class)],
            'request_type' => ['sometimes', 'required', new Enum(SiteRequestTypeEnum::class)],
            'required_date' => 'sometimes|nullable|date_format:Y-m-d|after_or_equal:today',
            'notes' => 'sometimes|nullable|string|max:65535',
            'files' => 'sometimes|nullable|array|max:10',
            'files.*' => 'sometimes|required|file|image|mimes:jpeg,png,jpg,gif|max:5120',
            
            'personnel_type' => ['sometimes', 'nullable', new Enum(PersonnelTypeEnum::class)],
            'personnel_count' => 'sometimes|nullable|integer|min:1|max:100',
            'personnel_requirements' => 'sometimes|nullable|string|max:2000',
            'hourly_rate' => 'sometimes|nullable|numeric|min:0|max:10000',
            'work_hours_per_day' => 'sometimes|nullable|integer|min:1|max:24',
            'work_start_date' => 'sometimes|nullable|date_format:Y-m-d|after_or_equal:today',
            'work_end_date' => 'sometimes|nullable|date_format:Y-m-d|after_or_equal:work_start_date',
            'work_location' => 'sometimes|nullable|string|max:500',
            'additional_conditions' => 'sometimes|nullable|string|max:2000',
        ];
    }

    public function messages(): array
    {
        return [
            'files.*.image' => 'Каждый файл должен быть изображением.',
            'files.*.mimes' => 'Разрешены только файлы jpeg, png, jpg, gif.',
            'files.*.max' => 'Максимальный размер каждого файла 5MB.',
            'files.max' => 'Можно загрузить не более 10 файлов.',
        ];
    }

    public function toDto(): SiteRequestDTO
    {
        $validatedData = $this->validated();
        /** @var SiteRequest $siteRequest */
        $siteRequest = $this->route('site_request');

        return new SiteRequestDTO(
            id: $siteRequest->id,
            organization_id: $siteRequest->organization_id,
            project_id: $validatedData['project_id'] ?? $siteRequest->project_id,
            user_id: $siteRequest->user_id,
            title: $validatedData['title'] ?? $siteRequest->title,
            description: $validatedData['description'] ?? $siteRequest->description,
            status: isset($validatedData['status']) ? SiteRequestStatusEnum::from($validatedData['status']) : $siteRequest->status,
            priority: isset($validatedData['priority']) ? SiteRequestPriorityEnum::from($validatedData['priority']) : $siteRequest->priority,
            request_type: isset($validatedData['request_type']) ? SiteRequestTypeEnum::from($validatedData['request_type']) : $siteRequest->request_type,
            required_date: $validatedData['required_date'] ?? $siteRequest->required_date,
            notes: $validatedData['notes'] ?? $siteRequest->notes,
            files: $this->file('files') ?? null
        );
    }
}