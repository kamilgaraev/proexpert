<?php

namespace App\Http\Requests\Api\V1\Mobile\SiteRequest;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use App\DTOs\SiteRequest\SiteRequestDTO;
use App\Enums\SiteRequest\SiteRequestStatusEnum;
use App\Enums\SiteRequest\SiteRequestPriorityEnum;
use App\Enums\SiteRequest\SiteRequestTypeEnum;
use Carbon\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use App\Models\SiteRequest; // Для получения siteRequest из роута

class UpdateSiteRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var SiteRequest $siteRequest */
        $siteRequest = $this->route('site_request');
        // Проверка, что пользователь обновляет свою заявку или имеет на это права (например, админ)
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
            organization_id: $siteRequest->organization_id, // Не меняется
            project_id: $validatedData['project_id'] ?? $siteRequest->project_id,
            user_id: $siteRequest->user_id, // Автор заявки не меняется
            title: $validatedData['title'] ?? $siteRequest->title,
            description: $validatedData['description'] ?? $siteRequest->description,
            status: isset($validatedData['status']) ? SiteRequestStatusEnum::from($validatedData['status']) : $siteRequest->status,
            priority: isset($validatedData['priority']) ? SiteRequestPriorityEnum::from($validatedData['priority']) : $siteRequest->priority,
            request_type: isset($validatedData['request_type']) ? SiteRequestTypeEnum::from($validatedData['request_type']) : $siteRequest->request_type,
            required_date: isset($validatedData['required_date']) ? Carbon::parse($validatedData['required_date']) : $siteRequest->required_date,
            notes: $validatedData['notes'] ?? $siteRequest->notes,
            files: $this->file('files') ?? null // Новые файлы, если переданы
        );
    }
} 