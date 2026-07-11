<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Requests;

use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\Concerns\AuthorizesEstimateGenerationRequest;
use Illuminate\Foundation\Http\FormRequest;

class SelectEstimateGenerationNormativeCandidateRequest extends FormRequest
{
    use AuthorizesEstimateGenerationRequest;

    public function authorize(): bool
    {
        return $this->authorizeEstimateGeneration('estimate_generation.select_normative');
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'state_version' => ['required', 'integer', 'min:0'],
            'work_item_key' => ['required', 'string', 'max:255'],
            'norm_id' => ['required', 'integer'],
            'selection_source' => ['nullable', 'string', 'in:offered_candidate,catalog_search'],
            'response_scope' => ['nullable', 'string', 'in:full,review_queue'],
        ];
    }
}
