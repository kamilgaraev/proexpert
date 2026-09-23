<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Mobile;

use App\Http\Responses\MobileResponse;
use App\Models\User;
use App\Services\Mobile\MobileProjectAccessResolver;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

final class StoreMobileActFieldConfirmationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organizationId = (int) $this->attributes->get('current_organization_id', 0);

        $user = $this->user();
        if ($organizationId <= 0 || ! $user instanceof User) {
            return false;
        }

        if ($user->can('act_reports.field_confirm', ['organization_id' => $organizationId])) {
            return true;
        }

        return collect(app(MobileProjectAccessResolver::class)->ids($user, $organizationId))
            ->contains(fn (int $projectId): bool => $user->can(
                'act_reports.field_confirm',
                ['organization_id' => $organizationId, 'project_id' => $projectId, 'strict_project_scope' => true]
            ));
    }

    public function rules(): array
    {
        return [
            'signature_data' => ['required', 'string', 'max:1400000'],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:128'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(MobileResponse::error(
            'Проверьте подпись и ключ запроса.',
            422,
            $validator->errors(),
        ));
    }
}
