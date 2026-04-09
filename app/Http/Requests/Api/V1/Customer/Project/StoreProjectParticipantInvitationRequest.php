<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Customer\Project;

use App\Enums\ProjectOrganizationRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

use function trans_message;

class StoreProjectParticipantInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'role' => ['required', 'string', Rule::in([
                ProjectOrganizationRole::GENERAL_CONTRACTOR->value,
                ProjectOrganizationRole::CONTRACTOR->value,
            ])],
            'organization_name' => ['nullable', 'string', 'max:255', 'required_without:organization_id'],
            'inn' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255', 'required_without:organization_id'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'message' => ['nullable', 'string', 'max:1000'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'role.in' => trans_message('customer.projects.invitations.validation.role_invalid'),
            'organization_name.required_without' => trans_message('customer.projects.invitations.validation.organization_name_required'),
            'email.required_without' => trans_message('customer.projects.invitations.validation.email_required'),
            'email.email' => trans_message('customer.projects.invitations.validation.email_invalid'),
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        $errors = (new ValidationException($validator))->errors();

        throw new HttpResponseException(
            \App\Http\Responses\CustomerResponse::error(
                trans_message('customer.validation_failed'),
                422,
                $errors
            )
        );
    }
}
