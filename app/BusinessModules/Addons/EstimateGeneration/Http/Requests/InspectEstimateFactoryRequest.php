<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Requests;

use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\Concerns\AuthorizesEstimateGenerationRequest;
use Illuminate\Foundation\Http\FormRequest;

final class InspectEstimateFactoryRequest extends FormRequest
{
    use AuthorizesEstimateGenerationRequest;

    public function authorize(): bool
    {
        return $this->authorizeEstimateGeneration('estimate_generation.review');
    }

    public function rules(): array
    {
        return ['state_version' => ['required', 'integer', 'min:0', 'max:2147483647'], 'request_id' => ['required', 'uuid']];
    }
}
