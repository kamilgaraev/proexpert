<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Requests;

use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\Concerns\AuthorizesEstimateGenerationRequest;
use Illuminate\Foundation\Http\FormRequest;

final class InterpretEstimateCommandRequest extends FormRequest
{
    use AuthorizesEstimateGenerationRequest;

    public function authorize(): bool
    {
        return $this->authorizeEstimateGeneration('estimate_generation.review');
    }

    public function rules(): array
    {
        $shared = \App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditPolicy::enabledForOrganization((int) $this->user()->current_organization_id);

        return ['command' => ['required', 'string', 'max:4000'], 'idempotency_key' => ['required', 'string', 'min:8', 'max:128', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]+$/'],
            'quote_id' => [$shared ? 'required' : 'sometimes', 'uuid'], 'request_id' => [$shared ? 'required' : 'sometimes', 'uuid', 'same:idempotency_key'],
            'state_version' => [$shared ? 'required' : 'sometimes', 'integer', 'min:0']];
    }
}
