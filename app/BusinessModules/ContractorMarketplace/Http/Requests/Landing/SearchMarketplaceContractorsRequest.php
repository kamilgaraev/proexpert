<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Http\Requests\Landing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchMarketplaceContractorsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organizationId = $this->attributes->get('current_organization_id') ?? $this->user()?->current_organization_id;

        return $this->user() !== null
            && $organizationId !== null
            && $this->user()->belongsToOrganization((int) $organizationId);
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', 'exists:marketplace_work_categories,id'],
            'city' => ['nullable', 'string', 'max:150'],
            'availability_status' => ['nullable', Rule::in(['available', 'busy', 'partially_available'])],
            'verification_level' => ['nullable', Rule::in(['none', 'basic', 'documents', 'verified'])],
            'min_rating' => ['nullable', 'numeric', 'min:0', 'max:5'],
            'team_capacity_min' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'budget_min' => ['nullable', 'numeric', 'min:0'],
            'budget_max' => ['nullable', 'numeric', 'min:0'],
            'sort_by' => ['nullable', Rule::in(['relevance', 'category_rating', 'name'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
