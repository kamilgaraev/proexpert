<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Public;

use App\Http\Responses\LandingResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

use function trans_message;

class StoreContactFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $prepared = [];

        foreach ([
            'name',
            'email',
            'phone',
            'company',
            'company_role',
            'company_size',
            'subject',
            'message',
            'consent_version',
            'page_source',
            'utm_source',
            'utm_medium',
            'utm_campaign',
            'utm_term',
            'utm_content',
        ] as $field) {
            $value = $this->input($field);

            if (is_string($value)) {
                $prepared[$field] = trim($value);
            }
        }

        if ($this->has('consent_to_personal_data')) {
            $prepared['consent_to_personal_data'] = filter_var(
                $this->input('consent_to_personal_data'),
                FILTER_VALIDATE_BOOLEAN
            );
        }

        $this->merge($prepared);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^[\+]?[0-9\-\(\)\s]+$/'],
            'company' => ['nullable', 'string', 'max:255'],
            'company_role' => ['nullable', 'string', 'max:120'],
            'company_size' => ['nullable', 'string', 'max:120'],
            'subject' => ['required', 'string', 'min:3', 'max:255'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
            'consent_to_personal_data' => ['accepted'],
            'consent_version' => ['required', 'string', 'max:120'],
            'page_source' => ['required', 'string', 'max:255'],
            'utm_source' => ['nullable', 'string', 'max:255'],
            'utm_medium' => ['nullable', 'string', 'max:255'],
            'utm_campaign' => ['nullable', 'string', 'max:255'],
            'utm_term' => ['nullable', 'string', 'max:255'],
            'utm_content' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => trans_message('public_contact.validation.name_required'),
            'name.min' => trans_message('public_contact.validation.name_min', ['min' => 2]),
            'name.max' => trans_message('public_contact.validation.name_max', ['max' => 255]),
            'email.required' => trans_message('public_contact.validation.email_required'),
            'email.email' => trans_message('public_contact.validation.email_email'),
            'email.max' => trans_message('public_contact.validation.email_max', ['max' => 255]),
            'phone.regex' => trans_message('public_contact.validation.phone_regex'),
            'phone.max' => trans_message('public_contact.validation.phone_max', ['max' => 20]),
            'company.max' => trans_message('public_contact.validation.company_max', ['max' => 255]),
            'company_role.max' => trans_message('public_contact.validation.company_role_max', ['max' => 120]),
            'company_size.max' => trans_message('public_contact.validation.company_size_max', ['max' => 120]),
            'subject.required' => trans_message('public_contact.validation.subject_required'),
            'subject.min' => trans_message('public_contact.validation.subject_min', ['min' => 3]),
            'subject.max' => trans_message('public_contact.validation.subject_max', ['max' => 255]),
            'message.required' => trans_message('public_contact.validation.message_required'),
            'message.min' => trans_message('public_contact.validation.message_min', ['min' => 10]),
            'message.max' => trans_message('public_contact.validation.message_max', ['max' => 5000]),
            'consent_to_personal_data.accepted' => trans_message('public_contact.validation.consent_required'),
            'consent_version.required' => trans_message('public_contact.validation.consent_version_required'),
            'consent_version.max' => trans_message('public_contact.validation.consent_version_max', ['max' => 120]),
            'page_source.required' => trans_message('public_contact.validation.page_source_required'),
            'page_source.max' => trans_message('public_contact.validation.page_source_max', ['max' => 255]),
            'utm_source.max' => trans_message('public_contact.validation.utm_max', ['max' => 255]),
            'utm_medium.max' => trans_message('public_contact.validation.utm_max', ['max' => 255]),
            'utm_campaign.max' => trans_message('public_contact.validation.utm_max', ['max' => 255]),
            'utm_term.max' => trans_message('public_contact.validation.utm_max', ['max' => 255]),
            'utm_content.max' => trans_message('public_contact.validation.utm_max', ['max' => 255]),
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => trans_message('public_contact.attributes.name'),
            'email' => trans_message('public_contact.attributes.email'),
            'phone' => trans_message('public_contact.attributes.phone'),
            'company' => trans_message('public_contact.attributes.company'),
            'company_role' => trans_message('public_contact.attributes.company_role'),
            'company_size' => trans_message('public_contact.attributes.company_size'),
            'subject' => trans_message('public_contact.attributes.subject'),
            'message' => trans_message('public_contact.attributes.message'),
            'consent_to_personal_data' => trans_message('public_contact.attributes.consent_to_personal_data'),
            'consent_version' => trans_message('public_contact.attributes.consent_version'),
            'page_source' => trans_message('public_contact.attributes.page_source'),
            'utm_source' => trans_message('public_contact.attributes.utm_source'),
            'utm_medium' => trans_message('public_contact.attributes.utm_medium'),
            'utm_campaign' => trans_message('public_contact.attributes.utm_campaign'),
            'utm_term' => trans_message('public_contact.attributes.utm_term'),
            'utm_content' => trans_message('public_contact.attributes.utm_content'),
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            LandingResponse::error(
                trans_message('public_contact.validation_failed'),
                422,
                $validator->errors()->toArray()
            )
        );
    }
}
