<?php

namespace App\Http\Requests\Api\V1\Mobile\SiteRequest; // Путь для мобильного API

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use App\DTOs\SiteRequest\SiteRequestDTO;
use App\Enums\SiteRequest\SiteRequestStatusEnum;
use App\Enums\SiteRequest\SiteRequestPriorityEnum;
use App\Enums\SiteRequest\SiteRequestTypeEnum;
use Carbon\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreSiteRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Предполагаем, что у прораба есть права на создание заявок (например, роль 'foreman')
        // return Auth::check() && Auth::user()->hasRole('foreman');
        return Auth::check(); // Упрощенная проверка, права лучше через middleware/gate
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
            'files' => 'nullable|array|max:10', // Максимум 10 файлов
            'files.*' => 'required|file|image|mimes:jpeg,png,jpg,gif|max:5120', // Каждый файл - изображение до 5MB
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
        
        return new SiteRequestDTO(
            id: null,
            organization_id: Auth::user()->current_organization_id,
            project_id: $validatedData['project_id'],
            user_id: Auth::id(), // ID текущего аутентифицированного пользователя (прораба)
            title: $validatedData['title'],
            description: $validatedData['description'] ?? null,
            status: SiteRequestStatusEnum::from($validatedData['status']),
            priority: SiteRequestPriorityEnum::from($validatedData['priority']),
            request_type: SiteRequestTypeEnum::from($validatedData['request_type']),
            required_date: isset($validatedData['required_date']) ? Carbon::parse($validatedData['required_date']) : null,
            notes: $validatedData['notes'] ?? null,
            files: $this->file('files') // Получаем загруженные файлы
        );
    }
} 