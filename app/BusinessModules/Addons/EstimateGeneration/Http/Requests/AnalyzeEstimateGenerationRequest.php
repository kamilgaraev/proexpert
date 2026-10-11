<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Requests;

use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\Concerns\AuthorizesEstimateGenerationRequest;
use Illuminate\Foundation\Http\FormRequest;

final class AnalyzeEstimateGenerationRequest extends FormRequest
{
    use AuthorizesEstimateGenerationRequest;

    public function authorize(): bool
    {
        return $this->authorizeEstimateGeneration('estimate_generation.generate');
    }

    public function rules(): array
    {
        return ['state_version' => ['required', 'integer', 'min:0'],
            'quote_id' => [\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditPolicy::enabledForOrganization((int) $this->user()->current_organization_id) ? 'required' : 'nullable', 'uuid'],
            'request_id' => [\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditPolicy::enabledForOrganization((int) $this->user()->current_organization_id) ? 'required' : 'nullable', 'uuid']];
    }
}
