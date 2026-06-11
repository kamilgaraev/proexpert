<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CrmCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'owner_user_id' => ['nullable', 'integer'],
            'linked_organization_id' => ['nullable', 'integer'],
            'linked_contractor_id' => ['nullable', 'integer'],
            'source_id' => ['nullable', 'uuid'],
            'source_ref_type' => ['nullable', 'string', 'max:64'],
            'source_ref_id' => ['nullable', 'string', 'max:128'],
            'name' => [$required, 'string', 'max:500'],
            'legal_name' => ['nullable', 'string', 'max:500'],
            'company_type' => ['nullable', 'string', Rule::in(['legal_entity', 'individual', 'holding', 'partner'])],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['string', 'max:64'],
            'status' => ['nullable', 'string', Rule::in(['new', 'active', 'paused', 'archived', 'merged'])],
            'inn' => ['nullable', 'string', 'max:32'],
            'kpp' => ['nullable', 'string', 'max:32'],
            'ogrn' => ['nullable', 'string', 'max:32'],
            'phone' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            'legal_address' => ['nullable', 'string', 'max:1000'],
            'actual_address' => ['nullable', 'string', 'max:1000'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:80'],
            'custom_fields' => ['nullable', 'array'],
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
