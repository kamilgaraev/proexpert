<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Requests;

use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\Concerns\AuthorizesEstimateGenerationRequest;
use Illuminate\Foundation\Http\FormRequest;

class RebuildEstimateGenerationSectionRequest extends FormRequest
{
    use AuthorizesEstimateGenerationRequest;

    public function authorize(): bool
    {
        return $this->authorizeEstimateGeneration('estimate_generation.generate');
    }

    public function rules(): array
    {
        $required = \App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditPolicy::enabledForOrganization((int) $this->user()->current_organization_id) ? 'required' : 'sometimes';

        return [
            'state_version' => ['required', 'integer', 'min:0'],
            'local_estimate_key' => ['required', 'string', 'max:255'],
            'quote_id' => [$required, 'uuid'],
            'request_id' => [$required, 'uuid'],
        ];
    }
}
