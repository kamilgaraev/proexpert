<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Requests;

use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\Concerns\AuthorizesEstimateGenerationRequest;
use Illuminate\Foundation\Http\FormRequest;

class ApplyEstimateGenerationDraftRequest extends FormRequest
{
    use AuthorizesEstimateGenerationRequest;

    public function authorize(): bool
    {
        return $this->authorizeEstimateGeneration('estimate_generation.apply');
    }

    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string'],
            'type' => ['nullable', 'in:local,object,summary,contractual'],
            'estimate_date' => ['nullable', 'date'],
        ];
    }
}
