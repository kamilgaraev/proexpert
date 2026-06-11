<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CrmContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'company_id' => ['nullable', 'uuid'],
            'owner_user_id' => ['nullable', 'integer'],
            'source_id' => ['nullable', 'uuid'],
            'source_ref_type' => ['nullable', 'string', 'max:64'],
            'source_ref_id' => ['nullable', 'string', 'max:128'],
            'full_name' => [$required, 'string', 'max:500'],
            'position' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:255'],
            'messengers' => ['nullable', 'array'],
            'is_primary' => ['nullable', 'boolean'],
            'status' => ['nullable', 'string', Rule::in(['active', 'inactive', 'archived', 'merged'])],
            'personal_data_consent_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'contact_points' => ['nullable', 'array'],
            'contact_points.*.point_type' => ['required_with:contact_points', 'string', 'max:32'],
            'contact_points.*.label' => ['nullable', 'string', 'max:120'],
            'contact_points.*.value' => ['required_with:contact_points', 'string', 'max:500'],
            'contact_points.*.is_primary' => ['nullable', 'boolean'],
            'contact_points.*.is_verified' => ['nullable', 'boolean'],
            'contact_points.*.metadata' => ['nullable', 'array'],
            'identities' => ['nullable', 'array'],
            'identities.*.identity_type' => ['required_with:identities', 'string', 'max:32'],
            'identities.*.value' => ['required_with:identities', 'string', 'max:500'],
            'identities.*.source' => ['nullable', 'string', 'max:64'],
            'identities.*.metadata' => ['nullable', 'array'],
        ];
    }
}
