<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Mobile;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Http\Responses\MobileResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

final class UpdateMobileScheduleTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $organizationId = (int) ($user?->current_organization_id ?? 0);

        return $user !== null && $organizationId > 0
            && app(AuthorizationService::class)->can($user, 'schedule.edit', [
                'organization_id' => $organizationId,
                'context_type' => 'organization',
            ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'planned_start_date' => ['sometimes', 'nullable', 'date'],
            'planned_end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:planned_start_date'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(MobileResponse::error(
            trans_message('schedule_management.validation_error'),
            422,
            $validator->errors(),
        ));
    }
}
