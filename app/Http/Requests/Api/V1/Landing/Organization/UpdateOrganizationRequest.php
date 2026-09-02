<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Landing\Organization;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

use function trans_message;

class UpdateOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        $organizationId = $this->currentOrganizationId();

        if (! $user || ! $organizationId) {
            return false;
        }

        return $user->hasPermission('organization.manage', [
            'organization_id' => $organizationId,
        ]);
    }

    public function rules(): array
    {
        $organization = $this->currentOrganization();

        return [
            'name' => 'sometimes|required|string|max:255|min:2',
            'legal_name' => 'nullable|string|max:255|min:2',
            'tax_number' => array_filter([
                'nullable',
                'string',
                'regex:/^(\d{10}|\d{12})$/',
                $this->uniqueRuleWhenChanged($organization, 'tax_number'),
            ]),
            'registration_number' => array_filter([
                'nullable',
                'string',
                'regex:/^(\d{13}|\d{15})$/',
                $this->uniqueRuleWhenChanged($organization, 'registration_number'),
            ]),
            'okpo' => [
                'nullable',
                'string',
                'regex:/^(\d{8}|\d{10})$/',
            ],
            'phone' => [
                'nullable',
                'string',
                'max:20',
                'regex:/^(\+7|8)[- ]?\(?[0-9]{3}\)?[- ]?[0-9]{3}[- ]?[0-9]{2}[- ]?[0-9]{2}$/',
            ],
            'email' => array_filter([
                'nullable',
                'string',
                'email',
                'max:255',
                $this->uniqueRuleWhenChanged($organization, 'email'),
            ]),
            'address' => 'nullable|string|max:500|min:10',
            'city' => 'nullable|string|max:100|min:2|regex:/^[а-яёА-ЯЁa-zA-Z\s\-\.]+$/u',
            'postal_code' => [
                'nullable',
                'string',
                'regex:/^\d{6}$/',
            ],
            'country' => 'nullable|string|max:100|min:2',
            'description' => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => trans_message('organization.validation.name_required'),
            'name.min' => trans_message('organization.validation.name_min'),
            'legal_name.min' => trans_message('organization.validation.legal_name_min'),
            'tax_number.regex' => trans_message('organization.validation.tax_number_format'),
            'tax_number.unique' => trans_message('organization.validation.tax_number_unique'),
            'registration_number.regex' => trans_message('organization.validation.registration_number_format'),
            'registration_number.unique' => trans_message('organization.validation.registration_number_unique'),
            'okpo.regex' => trans_message('organization.validation.okpo_format'),
            'phone.regex' => trans_message('organization.validation.phone_format'),
            'email.email' => trans_message('organization.validation.email_format'),
            'email.unique' => trans_message('organization.validation.email_unique'),
            'address.min' => trans_message('organization.validation.address_min'),
            'address.max' => trans_message('organization.validation.address_max'),
            'city.min' => trans_message('organization.validation.city_min'),
            'city.regex' => trans_message('organization.validation.city_format'),
            'postal_code.regex' => trans_message('organization.validation.postal_code_format'),
            'country.min' => trans_message('organization.validation.country_min'),
            'description.max' => trans_message('organization.validation.description_max'),
        ];
    }

    private function currentOrganizationId(): ?int
    {
        $organizationId = $this->attributes->get('current_organization_id')
            ?? Auth::user()?->current_organization_id;

        return is_numeric($organizationId) ? (int) $organizationId : null;
    }

    private function currentOrganization(): ?Organization
    {
        $organizationId = $this->currentOrganizationId();

        return $organizationId
            ? Organization::query()->find($organizationId)
            : null;
    }

    private function uniqueRuleWhenChanged(?Organization $organization, string $field): ?Unique
    {
        if ($organization && $this->input($field) === $organization->getAttribute($field)) {
            return null;
        }

        $rule = Rule::unique('organizations', $field);

        return $organization ? $rule->ignore($organization) : $rule;
    }
}
