<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Http\Requests\Admin;

use App\Enums\ProjectOrganizationRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreMarketplaceHiringOfferRequest extends FormRequest
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
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'contractor_profile_id' => ['required', 'integer', 'exists:marketplace_contractor_profiles,id'],
            'role' => ['required', 'string', Rule::in([
                ProjectOrganizationRole::CONTRACTOR->value,
                ProjectOrganizationRole::SUBCONTRACTOR->value,
                ProjectOrganizationRole::DESIGNER->value,
                ProjectOrganizationRole::CONSTRUCTION_SUPERVISION->value,
                ProjectOrganizationRole::OBSERVER->value,
            ])],
            'title' => ['required', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:2000'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'budget_min' => ['nullable', 'numeric', 'min:0'],
            'budget_max' => ['nullable', 'numeric', 'min:0', 'gte:budget_min'],
            'currency' => ['nullable', 'string', 'size:3'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'metadata' => ['nullable', 'array'],
            'work_packages' => ['required', 'array', 'min:1', 'max:50'],
            'work_packages.*.category_id' => ['required', 'integer', 'exists:marketplace_work_categories,id'],
            'work_packages.*.title' => ['required', 'string', 'max:255'],
            'work_packages.*.description' => ['nullable', 'string', 'max:2000'],
            'work_packages.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'work_packages.*.unit' => ['nullable', 'string', 'max:32'],
            'work_packages.*.budget_min' => ['nullable', 'numeric', 'min:0'],
            'work_packages.*.budget_max' => ['nullable', 'numeric', 'min:0'],
            'work_packages.*.starts_at' => ['nullable', 'date'],
            'work_packages.*.ends_at' => ['nullable', 'date'],
            'work_packages.*.metadata' => ['nullable', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ((array) $this->input('work_packages', []) as $index => $workPackage) {
                if (! is_array($workPackage)) {
                    continue;
                }

                if (
                    isset($workPackage['budget_min'], $workPackage['budget_max'])
                    && (float) $workPackage['budget_max'] < (float) $workPackage['budget_min']
                ) {
                    $validator->errors()->add(
                        "work_packages.{$index}.budget_max",
                        trans_message('contractor_marketplace.offer_budget_invalid')
                    );
                }

                if (
                    isset($workPackage['starts_at'], $workPackage['ends_at'])
                    && strtotime((string) $workPackage['ends_at']) < strtotime((string) $workPackage['starts_at'])
                ) {
                    $validator->errors()->add(
                        "work_packages.{$index}.ends_at",
                        trans_message('contractor_marketplace.offer_dates_invalid')
                    );
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'work_packages.required' => trans_message('contractor_marketplace.offer_work_packages_required'),
            'work_packages.min' => trans_message('contractor_marketplace.offer_work_packages_required'),
            'work_packages.*.category_id.exists' => trans_message('contractor_marketplace.category_not_found'),
            'role.in' => trans_message('contractor_marketplace.offer_role_invalid'),
            'budget_max.gte' => trans_message('contractor_marketplace.offer_budget_invalid'),
            'ends_at.after_or_equal' => trans_message('contractor_marketplace.offer_dates_invalid'),
            'expires_at.after' => trans_message('contractor_marketplace.offer_expiration_invalid'),
        ];
    }
}
