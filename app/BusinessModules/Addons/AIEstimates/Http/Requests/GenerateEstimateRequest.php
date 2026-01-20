<?php

namespace App\BusinessModules\Addons\AIEstimates\Http\Requests;

use App\Http\Responses\AdminResponse;
use Illuminate\Foundation\Http\FormRequest;

use function trans_message;

class GenerateEstimateRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Используем AuthorizationService для проверки прав, так как он поддерживает wildcard (*)
        /** @var \App\Domain\Authorization\Services\AuthorizationService $authService */
        $authService = app(\App\Domain\Authorization\Services\AuthorizationService::class);
        
        // Получаем контекст организации из запроса
        $user = $this->user();
        $organizationId = $user->current_organization_id;
        $context = $organizationId ? ['organization_id' => $organizationId] : null;
        
        return $authService->can($user, 'ai-estimates.generate', $context);
    }

    protected function failedAuthorization()
    {
        throw new \Illuminate\Http\Exceptions\HttpResponseException(
            AdminResponse::error(trans_message('ai_estimates.access_denied'), 403)
        );
    }

    public function rules(): array
    {
        $maxFileSize = config('ai-estimates.max_file_size', 50) * 1024; // KB
        $allowedTypes = config('ai-estimates.allowed_file_types', ['pdf', 'jpg', 'jpeg', 'png', 'xlsx', 'xls']);

        return [
            'description' => ['required', 'string', 'min:10', 'max:10000'],
            'area' => ['nullable', 'numeric', 'min:1', 'max:1000000'],
            'building_type' => ['nullable', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:255'],
            'files' => ['nullable', 'array', 'max:10'],
            'files.*' => [
                'file',
                'max:' . $maxFileSize,
                'mimes:' . implode(',', $allowedTypes),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'description.required' => 'Описание проекта обязательно для генерации сметы',
            'description.min' => 'Описание должно содержать минимум 10 символов',
            'description.max' => 'Описание не должно превышать 10000 символов',
            'area.numeric' => 'Площадь должна быть числом',
            'area.min' => 'Площадь должна быть больше 0',
            'files.max' => 'Можно загрузить максимум 10 файлов',
            'files.*.max' => 'Размер файла не должен превышать ' . config('ai-estimates.max_file_size', 50) . 'MB',
        ];
    }

    public function attributes(): array
    {
        return [
            'description' => 'Описание проекта',
            'area' => 'Площадь',
            'building_type' => 'Тип здания',
            'region' => 'Регион',
            'files' => 'Файлы',
        ];
    }
}
