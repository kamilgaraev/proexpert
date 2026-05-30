<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Http\Requests\Landing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMarketplaceProfileRequest extends FormRequest
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
            'display_name' => ['nullable', 'string', 'max:255'],
            'short_description' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:5000'],
            'team_size_min' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'team_size_max' => ['nullable', 'integer', 'min:1', 'max:100000', 'gte:team_size_min'],
            'years_on_market' => ['nullable', 'integer', 'min:0', 'max:200'],
            'base_city' => ['nullable', 'string', 'max:255'],
            'service_radius_km' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'availability_status' => ['nullable', Rule::in(['available', 'busy', 'partially_available', 'hidden'])],
            'available_from' => ['nullable', 'date'],
            'verification_level' => ['nullable', Rule::in(['none', 'basic', 'documents', 'verified'])],
            'metadata' => ['nullable', 'array', 'max:20'],
            'categories' => ['nullable', 'array', 'max:50'],
            'categories.*.category_id' => ['required_with:categories', 'integer', 'exists:marketplace_work_categories,id'],
            'categories.*.is_primary' => ['nullable', 'boolean'],
            'categories.*.experience_years' => ['nullable', 'integer', 'min:0', 'max:200'],
            'categories.*.team_capacity' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'categories.*.min_project_budget' => ['nullable', 'numeric', 'min:0'],
            'categories.*.max_project_budget' => ['nullable', 'numeric', 'min:0'],
            'regions' => ['nullable', 'array', 'max:50'],
            'regions.*.country' => ['nullable', 'string', 'max:100'],
            'regions.*.region' => ['nullable', 'string', 'max:150'],
            'regions.*.city' => ['nullable', 'string', 'max:150'],
            'regions.*.is_primary' => ['nullable', 'boolean'],
            'portfolio_items' => ['nullable', 'array', 'max:50'],
            'portfolio_items.*.category_id' => ['nullable', 'integer', 'exists:marketplace_work_categories,id'],
            'portfolio_items.*.title' => ['required_with:portfolio_items', 'string', 'max:255'],
            'portfolio_items.*.description' => ['nullable', 'string', 'max:3000'],
            'portfolio_items.*.city' => ['nullable', 'string', 'max:150'],
            'portfolio_items.*.completed_at' => ['nullable', 'date'],
            'portfolio_items.*.media' => ['nullable', 'array', 'max:20'],
            'portfolio_items.*.metadata' => ['nullable', 'array', 'max:20'],
        ];
    }

    public function messages(): array
    {
        return [
            'team_size_max.gte' => trans_message('contractor_marketplace.team_size_invalid'),
            'categories.*.category_id.exists' => trans_message('contractor_marketplace.category_not_found'),
            'portfolio_items.*.category_id.exists' => trans_message('contractor_marketplace.category_not_found'),
            'portfolio_items.*.title.required_with' => trans_message('contractor_marketplace.portfolio_title_required'),
        ];
    }
}
