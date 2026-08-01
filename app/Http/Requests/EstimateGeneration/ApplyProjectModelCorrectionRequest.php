<?php

declare(strict_types=1);

namespace App\Http\Requests\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\Concerns\AuthorizesEstimateGenerationRequest;
use Illuminate\Foundation\Http\FormRequest;

final class ApplyProjectModelCorrectionRequest extends FormRequest
{
    use AuthorizesEstimateGenerationRequest;

    public function authorize(): bool
    {
        return $this->authorizeEstimateGeneration('estimate_generation.review');
    }

    public function rules(): array
    {
        return [
            'expected_source_version' => ['required', 'string', 'regex:/^sha256:[a-f0-9]{64}$/'],
            'expected_value_fingerprint' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'assertion_stable_key' => ['required', 'string', 'regex:/^[a-z][a-z0-9:_-]{0,191}$/'],
            'value' => $this->isRevertCommand() ? ['prohibited'] : ['required', 'array', 'min:1', 'max:4'],
            'reason' => ['required', 'string', 'min:1', 'max:1000'],
            'idempotency_key' => ['required', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $headerKey = $this->header('Idempotency-Key');
        if (is_string($headerKey) && $headerKey !== '') {
            $this->merge(['idempotency_key' => $headerKey]);
        }
    }

    private function isRevertCommand(): bool
    {
        return str_ends_with($this->path(), 'project-model/corrections/revert');
    }
}
