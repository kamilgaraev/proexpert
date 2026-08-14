<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Requests;

use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\Concerns\AuthorizesEstimateGenerationRequest;
use Illuminate\Foundation\Http\FormRequest;

final class AnswerEstimateClarificationRequest extends FormRequest
{
    use AuthorizesEstimateGenerationRequest;

    public function authorize(): bool
    {
        return $this->authorizeEstimateGeneration('estimate_generation.review');
    }

    public function rules(): array
    {
        return [
            'response' => ['required', 'string', 'regex:/^(?:select:[a-f0-9]{64}|other|leave_unresolved)$/D'],
            'other' => ['nullable', 'string', 'max:500', 'required_if:response,other', 'prohibited_unless:response,other'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:128', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]+$/D'],
            'expected_source_version' => ['required', 'string', 'regex:/^sha256:[a-f0-9]{64}$/D'],
            'answer_fingerprint' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
        ];
    }
}
