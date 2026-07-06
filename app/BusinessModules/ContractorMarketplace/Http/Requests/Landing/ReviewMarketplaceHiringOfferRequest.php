<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Http\Requests\Landing;

use Illuminate\Foundation\Http\FormRequest;

class ReviewMarketplaceHiringOfferRequest extends FormRequest
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
            'reviews' => ['required', 'array', 'min:1', 'max:20'],
            'reviews.*.category_id' => ['required', 'integer', 'distinct', 'exists:marketplace_work_categories,id'],
            'reviews.*.quality_score' => ['required', 'numeric', 'between:1,5'],
            'reviews.*.deadline_score' => ['required', 'numeric', 'between:1,5'],
            'reviews.*.communication_score' => ['required', 'numeric', 'between:1,5'],
            'reviews.*.safety_score' => ['nullable', 'numeric', 'between:1,5'],
            'reviews.*.financial_discipline_score' => ['nullable', 'numeric', 'between:1,5'],
            'reviews.*.comment' => ['nullable', 'string', 'max:2000'],
            'reviews.*.metadata' => ['nullable', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'reviews.required' => trans_message('contractor_marketplace.offer_review_required'),
            'reviews.min' => trans_message('contractor_marketplace.offer_review_required'),
            'reviews.*.category_id.exists' => trans_message('contractor_marketplace.category_not_found'),
            'reviews.*.category_id.distinct' => trans_message('contractor_marketplace.offer_review_category_duplicate'),
            'reviews.*.*_score.between' => trans_message('contractor_marketplace.offer_review_score_invalid'),
        ];
    }
}
