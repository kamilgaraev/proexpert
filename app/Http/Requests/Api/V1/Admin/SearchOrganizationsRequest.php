<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Support\Organization\OrganizationWorkspaceProfileCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchOrganizationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'verified' => 'nullable|in:true,false,1,0',
            'exclude_invited' => 'nullable|in:true,false,1,0',
            'exclude_existing_contractors' => 'nullable|in:true,false,1,0',
            'sort_by' => 'nullable|string|in:relevance,name,city,connections,verified',
            'per_page' => 'nullable|integer|min:1|max:50',
            'page' => 'nullable|integer|min:1',
            'interaction_modes' => 'nullable|array|max:3',
            'interaction_modes.*' => ['required', 'string', 'distinct', Rule::in([
                OrganizationWorkspaceProfileCatalog::INTERACTION_PROJECT_PARTICIPANT,
                OrganizationWorkspaceProfileCatalog::INTERACTION_PROCUREMENT_COUNTERPARTY,
                OrganizationWorkspaceProfileCatalog::INTERACTION_SERVICE_COUNTERPARTY,
            ])],
        ];
    }
}
