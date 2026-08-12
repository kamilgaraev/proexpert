<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Requests;

use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\Concerns\AuthorizesEstimateGenerationRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListEstimateGenerationReviewItemsRequest extends FormRequest
{
    use AuthorizesEstimateGenerationRequest;

    public function authorize(): bool
    {
        return $this->authorizeEstimateGeneration('estimate_generation.view');
    }

    public function rules(): array
    {
        return [
            'severity' => ['nullable', 'string', Rule::in(['blocking', 'warning', 'optional'])],
            'required_action' => ['nullable', 'string', Rule::in([
                'confirm_quantity',
                'select_norm',
                'review_norm',
                'resolve_duplicate',
                'resolve_generic_work',
                'check_price',
            ])],
            'search' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', 'string', Rule::in([
                'conflict',
                'missing_required_data',
                'low_confidence',
                'technology_recommendation',
                'informational',
            ])],
            'floor' => ['nullable', 'string', 'max:80'],
            'room' => ['nullable', 'string', 'max:80'],
            'section' => ['nullable', 'string', 'max:160'],
            'origin' => ['nullable', 'string', Rule::in(['stage4', 'stage5', 'stage6', 'draft'])],
            'cost_impact' => ['nullable', 'string', Rule::in(['known', 'unknown', 'not_applicable'])],
            'unresolved_type' => ['nullable', 'string', 'max:80'],
            'cursor' => ['nullable', 'string', 'max:2048'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
