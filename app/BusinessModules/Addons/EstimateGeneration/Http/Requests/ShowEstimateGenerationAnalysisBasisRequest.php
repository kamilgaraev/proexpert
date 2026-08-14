<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Requests;

use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\Concerns\AuthorizesEstimateGenerationRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ShowEstimateGenerationAnalysisBasisRequest extends FormRequest
{
    use AuthorizesEstimateGenerationRequest;

    public function authorize(): bool
    {
        return $this->authorizeEstimateGeneration('estimate_generation.view');
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['quantity', 'question'])],
            'id' => ['required', 'string', 'min:1', 'max:180', 'regex:/^[a-zA-Z0-9._:-]+$/D'],
        ];
    }
}
