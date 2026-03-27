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
        $locale = $this->fallbackLocale();

        return [
            'name.required' => trans_message('public_contact.validation.name_required', [], $locale),
            'name.min' => trans_message('public_contact.validation.name_min', ['min' => 2], $locale),
            'name.max' => trans_message('public_contact.validation.name_max', ['max' => 255], $locale),
            'email.required' => trans_message('public_contact.validation.email_required', [], $locale),
            'email.email' => trans_message('public_contact.validation.email_email', [], $locale),
            'email.max' => trans_message('public_contact.validation.email_max', ['max' => 255], $locale),
            'phone.regex' => trans_message('public_contact.validation.phone_regex', [], $locale),
            'phone.max' => trans_message('public_contact.validation.phone_max', ['max' => 20], $locale),
            'company.max' => trans_message('public_contact.validation.company_max', ['max' => 255], $locale),
            'company_role.max' => trans_message('public_contact.validation.company_role_max', ['max' => 120], $locale),
            'company_size.max' => trans_message('public_contact.validation.company_size_max', ['max' => 120], $locale),
            'subject.required' => trans_message('public_contact.validation.subject_required', [], $locale),
            'subject.min' => trans_message('public_contact.validation.subject_min', ['min' => 3], $locale),
            'subject.max' => trans_message('public_contact.validation.subject_max', ['max' => 255], $locale),
            'message.required' => trans_message('public_contact.validation.message_required', [], $locale),
            'message.min' => trans_message('public_contact.validation.message_min', ['min' => 10], $locale),
            'message.max' => trans_message('public_contact.validation.message_max', ['max' => 5000], $locale),
            'consent_to_personal_data.accepted' => trans_message('public_contact.validation.consent_required', [], $locale),
            'consent_version.required' => trans_message('public_contact.validation.consent_version_required', [], $locale),
            'consent_version.max' => trans_message('public_contact.validation.consent_version_max', ['max' => 120], $locale),
            'page_source.required' => trans_message('public_contact.validation.page_source_required', [], $locale),
            'page_source.max' => trans_message('public_contact.validation.page_source_max', ['max' => 255], $locale),
            'utm_source.max' => trans_message('public_contact.validation.utm_max', ['max' => 255], $locale),
            'utm_medium.max' => trans_message('public_contact.validation.utm_max', ['max' => 255], $locale),
            'utm_campaign.max' => trans_message('public_contact.validation.utm_max', ['max' => 255], $locale),
            'utm_term.max' => trans_message('public_contact.validation.utm_max', ['max' => 255], $locale),
            'utm_content.max' => trans_message('public_contact.validation.utm_max', ['max' => 255], $locale),
        ];
    }

    public function attributes(): array
    {
        $locale = $this->fallbackLocale();

        return [
            'name' => trans_message('public_contact.attributes.name', [], $locale),
            'email' => trans_message('public_contact.attributes.email', [], $locale),
            'phone' => trans_message('public_contact.attributes.phone', [], $locale),
            'company' => trans_message('public_contact.attributes.company', [], $locale),
            'company_role' => trans_message('public_contact.attributes.company_role', [], $locale),
            'company_size' => trans_message('public_contact.attributes.company_size', [], $locale),
            'subject' => trans_message('public_contact.attributes.subject', [], $locale),
            'message' => trans_message('public_contact.attributes.message', [], $locale),
            'consent_to_personal_data' => trans_message('public_contact.attributes.consent_to_personal_data', [], $locale),
            'consent_version' => trans_message('public_contact.attributes.consent_version', [], $locale),
            'page_source' => trans_message('public_contact.attributes.page_source', [], $locale),
            'utm_source' => trans_message('public_contact.attributes.utm_source', [], $locale),
            'utm_medium' => trans_message('public_contact.attributes.utm_medium', [], $locale),
            'utm_campaign' => trans_message('public_contact.attributes.utm_campaign', [], $locale),
            'utm_term' => trans_message('public_contact.attributes.utm_term', [], $locale),
            'utm_content' => trans_message('public_contact.attributes.utm_content', [], $locale),
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            LandingResponse::error(
                trans_message('public_contact.validation_failed', [], $this->fallbackLocale()),
                422,
                $validator->errors()->toArray()
            )
        );
    }

    protected function fallbackLocale(): string
    {
        $locale = config('app.fallback_locale', 'ru');

        return is_string($locale) && $locale !== '' ? $locale : 'ru';
    }
}
