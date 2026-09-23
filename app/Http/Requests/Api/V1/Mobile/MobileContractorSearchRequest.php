<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Mobile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class MobileContractorSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:200'],
            'category_id' => ['nullable', 'integer', 'exists:marketplace_work_categories,id'],
            'team_capacity_min' => ['nullable', 'integer', 'min:1'],
            'budget_min' => ['nullable', 'numeric', 'min:0'],
            'budget_max' => ['nullable', 'numeric', 'min:0', 'gte:budget_min'],
            'min_rating' => ['nullable', 'numeric', 'between:0,5'],
            'city' => ['nullable', 'string', 'max:120'],
            'availability_status' => ['nullable', Rule::in(['available', 'partially_available', 'busy'])],
            'verification_level' => ['nullable', 'string', 'max:40'],
            'sort_by' => ['nullable', Rule::in(['relevance', 'name', 'category_rating'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
